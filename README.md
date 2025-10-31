# 🧠 AI Document Extractor

This PHP web application extracts structured data (like **name, address, NIF**, etc.) from uploaded documents (PDFs, images, or text files) using **OCR (Tesseract)** and **AI models** (via Ollama API). Extracted results are stored in a **MySQL database** and displayed in a clean, responsive interface built with **Tailwind CSS**.

---

## 🚀 Features

- 📤 **Upload multiple documents** (PDF, DOC/DOCX, PNG, JPG)
- 🧾 **OCR text extraction** using Tesseract or AI Vision model
- 🤖 **Structured data parsing** using Ollama LLM models
- 🗃️ **Auto-create MySQL table** for extractions
- 📊 **Dynamic progress bar**, live timer, and responsive UI
- 🌙 **Dark mode support**

---

## ⚙️ Requirements

- PHP 8.0+
- MySQL 5.7+ or MariaDB 10+
- [Tesseract OCR](https://tesseract-ocr.github.io/) installed and available in `$PATH`
- [poppler-utils](https://poppler.freedesktop.org/) (`pdftoppm`) for PDF conversion
- [Ollama](https://ollama.ai/) server with text and vision models accessible via HTTP

---

## 🧩 Configuration

Edit the constants at the top of the PHP file:

```php
define('DB_HOST', '192.168.0.161');
define('DB_NAME', 'ai');
define('DB_USER', 'root');
define('DB_PASS', 'your_password_here');
define('DB_CHARSET', 'utf8mb4');

define('OLLAMA_BASE', 'http://xxx.xxx.xxx.xxx:11434');
define('OLLAMA_VISION_MODEL', 'llama3.2-vision:latest');
define('OLLAMA_TEXT_MODEL', 'gpt-oss:120b');
```

### Notes
- Ensure the `OLLAMA_BASE` points to a reachable Ollama API endpoint.
- Adjust memory and timeout limits as needed:
  ```php
  ini_set('memory_limit', '30G');
  set_time_limit(1800);
  ```

---

## 🗂️ Folder Structure

```
project/
│
├── index.php                 # Main PHP file (UI + backend logic)
├── php_errors.log            # Error log file
└── uploads/                  # (optional) Uploaded files or temp data
```

---

## 🧠 How It Works

1. **Upload** one or more documents via the web UI.
2. Files are processed by:
   - `extract_text_local()` → Extracts text using **Tesseract** or **Ollama Vision**.
   - `call_ollama_extraction()` → Sends OCR text to **Ollama Text Model** for JSON extraction.
3. Extracted data is **cleaned**, **inserted into MySQL**, and **displayed** on-screen.

---

## 💡 Example Response

After uploading a file, the API returns:

```json
{
  "ok": true,
  "data": {
    "first_name_owner": "John",
    "surname_owner": "Smith",
    "owner_nif": "123456789",
    "property_address": "Rua das Flores 45, Lisbon",
    "value_€": "250000"
  },
  "insert_id": 15
}
```

---

## 🧰 Dependencies

Install required packages:

```bash
sudo apt update
sudo apt install php php-curl php-pdo php-mbstring php-fileinfo tesseract-ocr poppler-utils
```

Make sure the web server has permission to write logs and temporary files.

---

## 🧪 Testing

To test locally:
1. Run PHP’s built-in server:
   ```bash
   php -S localhost:8080
   ```
2. Open [http://localhost:8080](http://localhost:8080) in a browser.
3. Upload sample PDFs or images and check extracted results.

---

## 🔒 Security Notes

- Change default database credentials.
- Limit upload file size and sanitize file names.
- Consider placing this behind authentication if deployed publicly.
- Logs are stored in `php_errors.log` — monitor and rotate them regularly.

---

## 🧑‍💻 Author

**Rytis Petkevicius**  
© 2025 — All Rights Reserved.

---

## 🪪 License

This project is licensed under the **MIT License** — free for commercial and personal use.
