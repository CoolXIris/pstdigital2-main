"""Skrip diagnosa lengkap Chatbot (Python Flask Service + Laravel App).

Menguji:
1. Konfigurasi file .env (Python & Laravel)
2. Koneksi MySQL & keberadaan tabel cache BPS
3. Koneksi langsung ke WebAPI BPS
4. Eksekusi lokal pipeline Python chatbot (Gemini + BPS tools)
5. Status HTTP Server Python di port 8001 (apakah server aktif mendengarkan)
6. Konfigurasi internal Laravel (Cache config, Token, URL, API Key)
7. Simulasi pemanggilan dari Laravel HTTP Client ke Python
8. Pemeriksaan log error terbaru di storage/logs/laravel.log
"""

import json
import os
import shutil
import subprocess
import sys
import time
import traceback
from pathlib import Path
from dotenv import load_dotenv

BASE_DIR = Path(__file__).resolve().parent
os.chdir(BASE_DIR)
load_dotenv(BASE_DIR / ".env")

print("=" * 65)
print(" DIAGNOSA KESELURUHAN CHATBOT: PYTHON + LARAVEL")
print("=" * 65)

# -------------------------------------------------------------
# [1] CEK FILE .ENV
# -------------------------------------------------------------
print("\n[1] Memeriksa file .env...")
gemini_key = os.getenv("GEMINI_API_KEY", "").strip()
bps_key = os.getenv("BPS_API_KEY", "").strip()
chat_token = os.getenv("GEMINI_CHAT_API_TOKEN", "").strip()
chat_url = os.getenv("GEMINI_CHAT_API_URL", "http://127.0.0.1:8001/api/chat").strip()
model = os.getenv("GEMINI_MODEL", "gemini-flash-lite-latest").strip()
db_host = os.getenv("DB_HOST", "127.0.0.1")
db_name = os.getenv("DB_DATABASE", "")
db_user = os.getenv("DB_USERNAME", "")

print(f"  - GEMINI_API_KEY       : {'[OK] Terisi' if gemini_key else '[GAGAL] KOSONG'}")
print(f"  - BPS_API_KEY          : {'[OK] Terisi' if bps_key else '[GAGAL] KOSONG'}")
print(f"  - GEMINI_CHAT_API_TOKEN: {'[OK] Terisi' if chat_token else '[GAGAL] KOSONG'}")
print(f"  - GEMINI_CHAT_API_URL  : {chat_url}")
print(f"  - GEMINI_MODEL         : {model}")
print(f"  - Target MySQL         : {db_user}@{db_host}/{db_name}")

# -------------------------------------------------------------
# [2] CEK KONEKSI MYSQL & TABEL CACHE BPS
# -------------------------------------------------------------
print("\n[2] Memeriksa MySQL dan tabel cache BPS...")
try:
    import bps_data_store
    con = bps_data_store.init_db()
    print("  -> [OK] Berhasil konek ke MySQL dan semua tabel cache BPS ditemukan.")
    con.close()
except Exception as exc:
    print(f"  -> [GAGAL] MySQL bermasalah: {exc}")

# -------------------------------------------------------------
# [3] CEK KONEKSI KE WEBAPI BPS
# -------------------------------------------------------------
print("\n[3] Memeriksa koneksi langsung ke WebAPI BPS...")
try:
    import httpx
    if not bps_key:
        print("  -> [GAGAL] BPS_API_KEY kosong di .env.")
    else:
        url = f"https://webapi.bps.go.id/v1/api/list/model/subject/lang/ind/domain/1600/key/{bps_key}/"
        response = httpx.get(url, timeout=12.0)
        data = response.json()
        if response.status_code == 200 and data.get("status") == "OK":
            print("  -> [OK] WebAPI BPS merespons dengan status OK 200.")
        else:
            print(f"  -> [GAGAL] WebAPI BPS status {response.status_code}: {data}")
except Exception as exc:
    print(f"  -> [GAGAL] Gagal menghubungi webapi.bps.go.id: {exc}")

# -------------------------------------------------------------
# [4] CEK PIPELINE INTERNAL PYTHON (Gemini + BPS Tool)
# -------------------------------------------------------------
print("\n[4] Menguji pipeline internal Python (Gemini + BPS Tools)...")
try:
    from chatbot import main
    req = main.ChatRequest(question="berapa IPM Sumatera Selatan 2024?")
    res = main.chat_endpoint(req, authorization=f"Bearer {chat_token}")
    reply = res.get("reply") or res.get("data") or ""
    print("  -> [OK] Python backend mandiri BERHASIL memproses dan merangkum data:")
    sample = (reply[:120] + "...").replace("\n", " ")
    print(f"     Preview: \"{sample}\"")
except Exception as exc:
    print(f"  -> [GAGAL] Chatbot pipeline error: {exc}")
    traceback.print_exc()

# -------------------------------------------------------------
# [5] CEK HTTP SERVER PYTHON (Port 8001)
# -------------------------------------------------------------
print("\n[5] Memeriksa apakah Server Python AKTIF di port 8001...")
server_running = False
try:
    import httpx
    health_url = chat_url.rsplit("/api/", 1)[0] + "/health"
    r = httpx.get(health_url, timeout=3.0)
    if r.status_code == 200:
        print(f"  -> [OK] Server Python aktif di {health_url} (status: 200 OK)")
        # Test endpoint chat lewat HTTP
        r_chat = httpx.post(
            chat_url,
            headers={"Authorization": f"Bearer {chat_token}"},
            json={"question": "halo"},
            timeout=5.0,
        )
        if r_chat.status_code == 200:
            print(f"  -> [OK] Request HTTP POST {chat_url} sukses (status: 200 OK)")
            server_running = True
        elif r_chat.status_code == 401:
            print("  -> [GAGAL] Endpoint mengembalikan 401 Unauthorized! Token tidak cocok.")
        else:
            print(f"  -> [GAGAL] Endpoint chat mengembalikan status {r_chat.status_code}: {r_chat.text}")
    else:
        print(f"  -> [GAGAL] Health check mengembalikan status {r.status_code}")
except httpx.ConnectError:
    print(f"  -> [PERINGATAN KRUSIAL] Tidak ada service Python yang berjalan di {chat_url}!")
    print("     Penyebab: Perintah 'python -m chatbot.main' belum dijalankan di terminal.")
    print("     Laravel TIDAK BISA menghubungi chatbot jika server ini tidak sedang aktif!")
except Exception as exc:
    print(f"  -> [PERINGATAN] Gagal menghubungi {chat_url}: {exc}")

# -------------------------------------------------------------
# [6] CEK KONFIGURASI LARAVEL & CACHE
# -------------------------------------------------------------
print("\n[6] Memeriksa konfigurasi Laravel...")
config_cache_file = BASE_DIR / "bootstrap" / "cache" / "config.php"
if config_cache_file.exists():
    print("  -> [PERINGATAN] Ditemukan file 'bootstrap/cache/config.php' (Config di-cache)!")
    print("     Jika ada perubahan pada file .env, Laravel TIDAK AKAN membacanya sampai cache dibersihkan.")
    print("     Saran: Jalankan 'php artisan config:clear'.")
else:
    print("  -> [OK] Config Laravel tidak di-cache (Laravel membaca .env secara langsung).")

php_binary = shutil.which("php") or "php"

laravel_bridge_script = BASE_DIR / ".diag_laravel_test.php"
laravel_bridge_script.write_text(
    r"""<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$result = [
    'gemini_chat_url' => config('services.gemini.chat_url'),
    'gemini_chat_token_set' => filled(config('services.gemini.chat_token')),
    'gemini_chat_token_preview' => substr((string)config('services.gemini.chat_token'), 0, 10) . '...',
    'bps_api_key_set' => filled(config('services.bps.api_key')),
    'db_connection' => config('database.default'),
    'db_database' => config('database.connections.' . config('database.default') . '.database'),
];

// Cek panggil service langsung dari Laravel Http client
try {
    $knowledge = app(App\Services\ChatbotKnowledgeService::class);
    $response = $knowledge->ask('halo', 'diag-session-' . time());
    $result['laravel_http_call_status'] = $response->status();
    $result['laravel_http_call_body'] = $response->json();
} catch (\Throwable $e) {
    $result['laravel_http_call_error'] = get_class($e) . ': ' . $e->getMessage();
}

// Cek alur lengkap lewat ChatbotController
try {
    $controller = app(App\Http\Controllers\ChatbotController::class);
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('askAi');
    $method->setAccessible(true);
    $knowledge = app(App\Services\ChatbotKnowledgeService::class);
    $aiReply = $method->invoke($controller, $knowledge, 'berapa IPM Sumatera Selatan 2024?', 'diag-session-2', null, []);
    $result['laravel_ask_ai_reply'] = is_string($aiReply) ? substr($aiReply, 0, 150) . '...' : $aiReply;
} catch (\Throwable $e) {
    $result['laravel_ask_ai_error'] = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode($result);
""",
    encoding="utf-8",
)

try:
    proc = subprocess.run(
        [php_binary, str(laravel_bridge_script)],
        capture_output=True,
        text=True,
        timeout=30,
    )
    if proc.returncode == 0 and proc.stdout.strip().startswith("{"):
        l_res = json.loads(proc.stdout.strip())
        print(f"  - Laravel membaca chat_url   : {l_res.get('gemini_chat_url')}")
        print(f"  - Laravel membaca token      : {'[OK] Terisi (' + l_res.get('gemini_chat_token_preview', '') + ')' if l_res.get('gemini_chat_token_set') else '[GAGAL] KOSONG'}")
        print(f"  - Laravel membaca BPS Key    : {'[OK] Terisi' if l_res.get('bps_api_key_set') else '[GAGAL] KOSONG'}")
        print(f"  - Laravel Database           : {l_res.get('db_connection')} -> {l_res.get('db_database')}")

        print("\n[7] Menguji pemanggilan dari Laravel HTTP Client ke Python...")
        if "laravel_http_call_status" in l_res:
            st = l_res["laravel_http_call_status"]
            if st == 200:
                print(f"  -> [OK] Laravel BERHASIL menghubungi Python Chatbot! (Status: {st})")
            else:
                print(f"  -> [GAGAL] Laravel menerima HTTP status {st}: {l_res.get('laravel_http_call_body')}")
        elif "laravel_http_call_error" in l_res:
            print(f"  -> [GAGAL] Laravel gagal menghubungi Python:")
            print(f"     Pesan: {l_res['laravel_http_call_error']}")

        print("\n[8] Menguji respon alur Controller Laravel (pertanyaan data)...")
        if l_res.get("laravel_ask_ai_reply"):
            print("  -> [OK] Controller Laravel BERHASIL menerima jawaban AI:")
            print(f"     Preview: \"{l_res['laravel_ask_ai_reply']}\"")
        else:
            err = l_res.get("laravel_ask_ai_error")
            if err:
                print(f"  -> [GAGAL] Controller error: {err}")
            else:
                print("  -> [GAGAL] Controller mengembalikan NULL (memicu pesan fallback 'AI sedang tidak tersedia').")
    else:
        print(f"  -> [GAGAL] Gagal mengeksekusi PHP Laravel: {proc.stderr or proc.stdout}")
except Exception as exc:
    print(f"  -> [GAGAL] Eksekusi PHP Laravel gagal: {exc}")
finally:
    if laravel_bridge_script.exists():
        try:
            laravel_bridge_script.unlink()
        except Exception:
            pass

# -------------------------------------------------------------
# [9] CEK LOG ERROR TERAKHIR DI LARAVEL
# -------------------------------------------------------------
print("\n[9] Memeriksa storage/logs/laravel.log (5 pesan peringatan/error terakhir)...")
log_file = BASE_DIR / "storage" / "logs" / "laravel.log"
if log_file.exists():
    try:
        content = log_file.read_text(encoding="utf-8", errors="ignore").splitlines()
        error_lines = [
            line for line in content
            if any(k in line.lower() for k in ("chatbot", "gemini", "connectionexception", "curl error", "502", "401", "429"))
        ]
        if error_lines:
            print("  Ditemukan riwayat error terkait chatbot di log:")
            for item in error_lines[-5:]:
                print(f"    * {item[:150]}")
        else:
            print("  -> Tidak ada catatan error chatbot khusus di log.")
    except Exception as exc:
        print(f"  -> Gagal membaca log: {exc}")
else:
    print("  -> File laravel.log belum ada.")

print("\n" + "=" * 65)
print(" DIAGNOSA SELESAI")
print("=" * 65)
