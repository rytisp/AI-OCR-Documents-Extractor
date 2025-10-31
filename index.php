<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

define('DB_HOST', '192.168.0.161');
define('DB_NAME', 'ai');
define('DB_USER', 'dbuser');
define('DB_PASS', 'your_pass');
define('DB_CHARSET', 'utf8mb4');

ini_set('memory_limit', '30G');
set_time_limit(1800);
ignore_user_abort(true);

define('OLLAMA_BASE', 'http://xxx.xxx.xxx.xxx:11434');
define('OLLAMA_VISION_MODEL', 'llama3.2-vision:latest');
define('OLLAMA_TEXT_MODEL', 'gpt-oss:120b');

function db_connect() {
  $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
  $opt = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ];
  return new PDO($dsn, DB_USER, DB_PASS, $opt);
}

function run_cmd($cmd) {
  $d = [1 => ['pipe','w'], 2 => ['pipe','w']];
  $p = proc_open($cmd, $d, $pipes);
  if (!is_resource($p)) return [1,'','proc_open_failed'];
  $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
  $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
  $code = proc_close($p);
  return [$code,$out,$err];
}

function preprocess_text_for_gpt($t) {
  $t = preg_replace('/[ \t]+/',' ',$t);
  $t = preg_replace('/\n{2,}/',"\n",$t);
  $t = preg_replace('/\f|\r|\x0c/','',$t);
  $t = preg_replace('/[^[:alnum:]\p{L}\p{N}\s,.;:\/\-\(\)ºª°@€]/u',' ',$t);
  $t = preg_replace('/\s{2,}/',' ',$t);
  return trim(mb_substr($t,0,300000,'UTF-8'));
}

function ollama_post($endpoint, array $payload) {
  $url = rtrim(OLLAMA_BASE, '/') . $endpoint;
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Expect:"],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 600,
    CURLOPT_CONNECTTIMEOUT => 10
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($resp === false) throw new Exception("LLM cURL error: $err");
  if ($code >= 400 || $code === 0) throw new Exception("LLM HTTP $code: ".substr($resp,0,400));
  $obj = json_decode($resp, true);
  if (!is_array($obj)) throw new Exception("Invalid JSON from LLM");
  return $obj;
}

function extract_text_local($path) {
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = finfo_file($finfo, $path);
  finfo_close($finfo);
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  if (!$ext && $mime === 'application/pdf') $ext = 'pdf';
  $tmpDir  = sys_get_temp_dir() . '/vision_' . uniqid();
  mkdir($tmpDir, 0777, true);
  $images = [];
  if ($ext === 'pdf') {
    $safe = "$tmpDir/in.pdf";
    copy($path, $safe);
    run_cmd("/usr/bin/pdftoppm -jpeg -gray -r 300 " . escapeshellarg($safe) . " " . escapeshellarg("$tmpDir/page"));
    $images = glob("$tmpDir/page-*.jpg");
  } else {
    $images = [$path];
  }
  $joined = '';
  $tess = trim(shell_exec('command -v tesseract'));
  foreach ($images as $img) {
    $pageText = '';
    if ($tess) {
      $outBase = $img . '.ocr';
      run_cmd("$tess " . escapeshellarg($img) . " " . escapeshellarg($outBase) . " -l por+eng --psm 6");
      if (file_exists($outBase . '.txt')) {
        $pageText = file_get_contents($outBase . '.txt');
      }
    }
    if (trim($pageText) === '') {
      $b64 = base64_encode(file_get_contents($img));
      $payload = [
        "model"    => OLLAMA_VISION_MODEL,
        "messages" => [[
          "role"    => "user",
          "content" => "Extract all visible text.",
          "images"  => [$b64]
        ]],
        "options"  => ["temperature" => 0],
        "stream"   => false
      ];
      try {
        $obj = ollama_post('/api/chat', $payload);
        $pageText = $obj['message']['content'] ?? '';
      } catch (Throwable $e) {}
    }
    $joined .= "\n" . $pageText;
  }
  return preprocess_text_for_gpt($joined);
}

function call_ollama_extraction($text) {
  $translatePrompt = "Detect if this text is not English. If it isn't, translate it to English while preserving numbers, names, and addresses. If it's already English, return it unchanged:\n\n$text";
  $translatePayload = [
    "model" => OLLAMA_TEXT_MODEL,
    "prompt" => $translatePrompt,
    "options" => ["temperature" => 0],
    "stream" => false
  ];
  $translationResp = ollama_post('/api/generate', $translatePayload);
  $translatedText = $translationResp['response'] ?? $text;
  if (!is_string($translatedText) || trim($translatedText) === '') $translatedText = $text;

  $fieldsList = "title_owner, first_name_owner, surname_owner, owner_address, owner_address_2, owner_address_3, owner_address_4, owner_town, owner_zip_code, owner_nif, owner_marital_status, owner_nationality, owner_passport_no, owner_passport_expiry, owner_email, owner_phone, partner_full_name, partner_nif, partner_nationality, partner_passport_no, partner_passport_expiry, partner_full_address, transaction_status, transaction_type, value_€, mortgage_other_commitments_€, property_status, property_address, street, street_number, town, neighborhood, district, country, zone, zip_code, property_ref, cadastral_ref_no, land_registration_no, parish, urban_article_number, habitation_licence_number, energy_rating, energy_certificate_number, energy_cert_expiry, net_area, gross_area, land_area, condominium_expenses";

  $prompt = "You are an expert data extraction model. From the text below, extract all possible relevant details and return them as a JSON object with these exact keys:
$fieldsList

Every field must exist. Use null if the data is missing. Return ONLY valid JSON (no markdown, no explanations).

Text to analyze:
$translatedText";

  $payload = [
    "model" => OLLAMA_TEXT_MODEL,
    "prompt" => $prompt,
    "options" => ["temperature" => 0],
    "stream" => false
  ];

  $resp = ollama_post('/api/generate', $payload);
  $json = $resp['response'] ?? '{}';

  if (!is_string($json)) $json = json_encode($json);
  if (!str_starts_with(trim($json), '{')) {
    if (preg_match('/\{.*\}/s', $json, $m)) $json = $m[0];
  }

  $data = json_decode($json, true);
  if (!is_array($data)) $data = [];
  return $data;
}

$fields = [
  "title_owner","first_name_owner","surname_owner","owner_address","owner_address_2",
  "owner_address_3","owner_address_4","owner_town","owner_zip_code","owner_nif",
  "owner_marital_status","owner_nationality","owner_passport_no","owner_passport_expiry",
  "owner_email","owner_phone","partner_full_name","partner_nif","partner_nationality",
  "partner_passport_no","partner_passport_expiry","partner_full_address","transaction_status",
  "transaction_type","value_€","mortgage_other_commitments_€","property_status","property_address",
  "street","street_number","town","neighborhood","district","country","zone","zip_code",
  "property_ref","cadastral_ref_no","land_registration_no","parish","urban_article_number",
  "habitation_licence_number","energy_rating","energy_certificate_number","energy_cert_expiry",
  "net_area","gross_area","land_area","condominium_expenses"
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  ob_clean();

  try {
    $pdo = db_connect();
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS extractions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        " . implode(",", array_map(fn($f) => "`$f` TEXT NULL", $fields)) . "
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    if (empty($_FILES['files']['name'][0])) throw new Exception("No files uploaded.");

    $finalData = array_fill_keys($fields, null);

    foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
      if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
      $txt = extract_text_local($tmp);
      $data = call_ollama_extraction($txt);
      file_put_contents(__DIR__.'/llm_output_debug.json', json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
      if (isset($data[0]) && is_array($data[0])) $data = $data[0];
      $normalized = [];
      foreach ($data as $key => $val) {
        $k = strtolower(trim($key));
        $k = str_replace([' ', '-', '(', ')'], '_', $k);
        $normalized[$k] = $val;
      }
      foreach ($fields as $f) {
        $lf = strtolower($f);
        if (isset($normalized[$lf])) $finalData[$f] = $normalized[$lf];
      }
    }

    $columns = [];
    $placeholders = [];
    $values = [];
    foreach ($fields as $f) {
      $columns[] = "`$f`";
      $placeholders[] = "?";
      $values[] = $finalData[$f];
    }

    $sql = "INSERT INTO extractions (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    echo json_encode(['ok' => true, 'insert_id' => $pdo->lastInsertId(), 'data' => $finalData]);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>AI Document Extractor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
    body {
        font-family: ui-sans-serif, system-ui;
    }

    td {
        word-break: break-word;
        vertical-align: top;
    }
    </style>
</head>

<body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen">
    <div class="min-h-screen flex flex-col lg:flex-row">
        <aside class="w-full lg:w-64 bg-blue-700 text-white p-6 flex flex-col justify-between">
            <div>
                <h1 class="text-2xl font-bold">AI Document Extractor</h1>
            </div>
            <footer class="text-blue-300 text-xs mt-auto pt-4 border-t border-blue-600">© <?=date('Y')?> Rytis
                Petkevicius</footer>
        </aside>

        <main class="flex-1 p-8 space-y-6">
            <section class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                <h2 class="text-xl font-semibold mb-3">Upload Files</h2>
                <form id="uploadForm" enctype="multipart/form-data">
                    <div id="drop"
                        class="border-2 border-dashed border-gray-400 p-6 rounded-lg text-center hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <p class="text-gray-600 dark:text-gray-300">Drag & drop or click to upload</p>
                        <input type="file" name="files[]" id="fileInput" multiple
                            accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" class="hidden">
                    </div>
                    <div id="fileList" class="mt-3 text-sm text-gray-500"></div>
                    <div class="mt-4 flex justify-between items-center">
                        <span id="timer" class="text-sm text-gray-500">Time: 0s</span>
                        <button type="submit" id="runBtn"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Run
                            Extraction</button>
                    </div>
                </form>
                <div id="status" class="text-sm text-gray-500 mt-3"></div>
                <div class="w-full bg-gray-200 rounded-full h-2.5 mt-2">
                    <div id="bar" class="bg-blue-600 h-2.5 rounded-full w-0"></div>
                </div>
            </section>

            <section class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                <h2 class="text-xl font-semibold mb-3">Results</h2>
                <div id="results" class="space-y-4"></div>
            </section>
        </main>
    </div>

    <script>
    const form = document.getElementById('uploadForm');
    const drop = document.getElementById('drop');
    const fileInput = document.getElementById('fileInput');
    const fileList = document.getElementById('fileList');
    const results = document.getElementById('results');
    const status = document.getElementById('status');
    const bar = document.getElementById('bar');
    const timer = document.getElementById('timer');
    let timerInterval, startTime;

    function startTimer() {
        startTime = Date.now();
        timerInterval = setInterval(() => {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            const minutes = Math.floor(elapsed / 60);
            const seconds = elapsed % 60;
            timer.textContent = `Time: ${minutes}m ${seconds}s`;
        }, 1000);
    }

    function stopTimer() {
        clearInterval(timerInterval);
    }

    drop.addEventListener('click', () => fileInput.click());
    drop.addEventListener('dragover', e => {
        e.preventDefault();
        drop.classList.add('bg-blue-50');
    });
    drop.addEventListener('dragleave', () => drop.classList.remove('bg-blue-50'));
    drop.addEventListener('drop', e => {
        e.preventDefault();
        drop.classList.remove('bg-blue-50');
        fileInput.files = e.dataTransfer.files;
        updateFileList();
    });
    fileInput.addEventListener('change', updateFileList);

    function updateFileList() {
        const files = [...fileInput.files];
        if (!files.length) {
            fileList.innerHTML = '<span class="text-gray-400">No files selected</span>';
            return;
        }
        fileList.innerHTML = files.map(f => `• ${f.name} (${(f.size / 1024).toFixed(1)} KB)`).join('<br>');
    }

    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (!fileInput.files.length) {
            alert('Please select at least one file.');
            return;
        }
        bar.style.width = '0%';
        status.textContent = 'Uploading...';
        startTimer();
        const fd = new FormData(form);
        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            stopTimer();
            if (!data.ok) {
                status.textContent = '❌ Error: ' + data.error;
                bar.style.width = '100%';
                return;
            }
            status.textContent = '✅ Extraction complete';
            bar.style.width = '100%';
            renderResults(data.data);
        } catch (err) {
            stopTimer();
            status.textContent = '❌ ' + err.message;
            bar.style.width = '100%';
        }
    });

    function renderResults(obj) {
        const groups = {
            'Owner Details': ['title_owner', 'first_name_owner', 'surname_owner', 'owner_address',
                'owner_address_2', 'owner_address_3', 'owner_address_4', 'owner_town', 'owner_zip_code',
                'owner_nif', 'owner_marital_status', 'owner_nationality', 'owner_passport_no',
                'owner_passport_expiry', 'owner_email', 'owner_phone'
            ],
            'Partner Details': ['partner_full_name', 'partner_nif', 'partner_nationality', 'partner_passport_no',
                'partner_passport_expiry', 'partner_full_address'
            ],
            'Transaction': ['transaction_status', 'transaction_type', 'value_€', 'mortgage_other_commitments_€'],
            'Property Info': ['property_status', 'property_address', 'street', 'street_number', 'town',
                'neighborhood', 'district', 'country', 'zone', 'zip_code', 'property_ref', 'cadastral_ref_no'
            ],
            'Registry & Compliance': ['land_registration_no', 'parish', 'urban_article_number',
                'habitation_licence_number', 'energy_rating', 'energy_certificate_number', 'energy_cert_expiry',
                'net_area', 'gross_area', 'land_area', 'condominium_expenses'
            ]
        };
        results.innerHTML = '';
        for (const [g, keys] of Object.entries(groups)) {
            const div = document.createElement('div');
            div.className = 'p-4 border rounded-lg bg-gray-50 dark:bg-gray-900';
            div.innerHTML = `<h3 class='font-semibold text-lg mb-2 text-blue-700 dark:text-blue-400'>${g}</h3>
        <table class='text-sm w-full'>${keys.map(k =>
          `<tr><td class='pr-4 text-gray-600 w-1/3'>${k.replace(/_/g,' ')}</td><td class='font-mono text-gray-800 dark:text-gray-200 break-words'>${obj[k] ?? '<span class="text-gray-400">null</span>'}</td></tr>`
        ).join('')}</table>`;
            results.appendChild(div);
        }
    }
    </script>
</body>

</html>
