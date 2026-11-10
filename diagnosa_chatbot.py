"""Skrip diagnosa lengkap chatbot untuk mengecek penyebab error di device lain."""

import os
import sys
import traceback
from dotenv import load_dotenv

load_dotenv()

print("=" * 50)
print(" DIAGNOSA KONEKSI CHATBOT & WEBAPI BPS")
print("=" * 50)

# 1. CEK ENV
print("\n[1] Memeriksa file .env...")
gemini_key = os.getenv("GEMINI_API_KEY", "").strip()
bps_key = os.getenv("BPS_API_KEY", "").strip()
chat_token = os.getenv("GEMINI_CHAT_API_TOKEN", "").strip()
model = os.getenv("GEMINI_MODEL", "gemini-flash-lite-latest").strip()
db_host = os.getenv("DB_HOST", "127.0.0.1")
db_name = os.getenv("DB_DATABASE", "")
db_user = os.getenv("DB_USERNAME", "")

print(f"  - GEMINI_API_KEY      : {'[OK] Terisi' if gemini_key else '[GAGAL] KOSONG'}")
print(f"  - BPS_API_KEY         : {'[OK] Terisi' if bps_key else '[GAGAL] KOSONG'}")
print(f"  - GEMINI_CHAT_API_TOKEN: {'[OK] Terisi' if chat_token else '[GAGAL] KOSONG'}")
print(f"  - GEMINI_MODEL        : {model}")
print(f"  - DB Target           : {db_user}@{db_host}/{db_name}")

# 2. CEK KONEKSI MYSQL & TABEL BPS
print("\n[2] Memeriksa MySQL dan tabel cache BPS...")
try:
    import bps_data_store
    con = bps_data_store.init_db()
    print("  -> [OK] Berhasil konek ke MySQL dan semua tabel cache BPS ditemukan.")
    con.close()
except Exception as exc:
    print(f"  -> [GAGAL] MySQL bermasalah: {exc}")

# 3. CEK KONEKSI KE WEBAPI BPS LANGSUNG
print("\n[3] Memeriksa koneksi langsung ke WebAPI BPS...")
try:
    import httpx
    if not bps_key:
        print("  -> [GAGAL] BPS_API_KEY kosong, tidak bisa mengetes WebAPI BPS.")
    else:
        url = f"https://webapi.bps.go.id/v1/api/list/model/subject/lang/ind/domain/1600/key/{bps_key}/"
        response = httpx.get(url, timeout=12.0)
        data = response.json()
        if response.status_code == 200 and data.get("status") == "OK":
            print("  -> [OK] WebAPI BPS merespons dengan status OK 200.")
        else:
            print(f"  -> [GAGAL] WebAPI BPS merespons status {response.status_code}: {data}")
except Exception as exc:
    print(f"  -> [GAGAL] Gagal menghubungi webapi.bps.go.id: {exc}")

# 4. CEK PEMANGGILAN CHATBOT ENDPOINT LENGKAP
print("\n[4] Menguji proses chatbot (Gemini + BPS Tools)...")
try:
    from chatbot import main
    req = main.ChatRequest(question="berapa IPM Sumatera Selatan 2025?")
    res = main.chat_endpoint(req, authorization=f"Bearer {chat_token}")
    reply = res.get("reply") or res.get("data") or ""
    print("  -> [OK] Chatbot BERHASIL menjawab pertanyaan data BPS:")
    print("-" * 50)
    print(reply[:300] + ("..." if len(reply) > 300 else ""))
    print("-" * 50)
except Exception as exc:
    print(f"  -> [GAGAL] Chatbot endpoint error: {exc}")
    print("\nDetail traceback:")
    traceback.print_exc()

print("\n" + "=" * 50)
print(" DIAGNOSA SELESAI")
print("=" * 50)
