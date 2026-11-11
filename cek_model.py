"""Skrip sederhana untuk melihat seluruh model Gemini yang aktif dan didukung oleh API Key Anda."""

import os
from dotenv import load_dotenv
from google import genai

load_dotenv()

api_key = os.getenv("GEMINI_API_KEY", "").strip()
if not api_key:
    print("[ERROR] GEMINI_API_KEY belum diisi di file .env!")
    exit(1)

print("=" * 60)
print(" DAFTAR MODEL GEMINI YANG TERSEDIA UNTUK API KEY ANDA")
print("=" * 60)

try:
    client = genai.Client(api_key=api_key)
    models = list(client.models.list())
    
    chat_models = []
    other_models = []

    for m in models:
        clean_name = m.name.replace("models/", "")
        actions = getattr(m, "supported_actions", []) or []
        
        # Filter model yang mendukung pembuatan teks/chat (generateContent)
        if not actions or "generateContent" in actions:
            if "flash" in clean_name or "pro" in clean_name:
                chat_models.append((clean_name, getattr(m, "display_name", "")))
            else:
                other_models.append((clean_name, getattr(m, "display_name", "")))

    print("\n[Rekomendasi untuk Chatbot / GEMINI_MODEL]:")
    for name, display in sorted(chat_models):
        print(f"  * {name:<30} ({display})")

    print("\n[Model Lainnya / Embedding / Khusus]:")
    for name, display in sorted(other_models):
        print(f"  - {name:<30} ({display})")

except Exception as e:
    print(f"[ERROR] Gagal mengambil daftar model: {e}")

print("\n" + "=" * 60)
print("Tip: Salin salah satu nama di atas ke file .env pada GEMINI_MODEL=...")
print("=" * 60)
