# Optional: AI replies and voice-to-text (from the AI bot)

Full working code is in **MML0/Assistant_Bot**, branch `bale-fa-premium`, under `src/`. It's useful if the chat bot later needs AI (moderation, an "AI partner while you wait") or voice-to-text.

## Free-first AI routing (`src/Router.php`, `ModelCatalog.php`, `ModelHealth.php`)
- **Tiers:** users see three tiers, ⚡ عادی / 🧠 هوشمند / 🚀 فوق هوشمند, never model names. Each tier lists free OpenRouter models in order, then a paid MetisAI model (`gpt-4.1-nano` / `-mini` / `gpt-4.1`).
- **Free models used:**
  - `nvidia/nemotron-3.5-lightning:free`
  - `nvidia/nemotron-3-super-120b-a12b:free`
  - `nvidia/nemotron-3-ultra-550b-a55b:free`
  - `thinkingmachines/inkling:free`
  - `nvidia/nemotron-3.5-super-vl:free`
  - `poolside/laguna-s-2.1:free`
  - `dots-studio/dots-3-note-preview:free`
- **OpenRouter API:**
  - `GET /api/v1/models?sort=intelligence-high-to-low` lists models. A model is free only if **every** price is `0`; never trust the `:free` name alone.
  - `GET /api/v1/benchmarks?source=artificial-analysis` gives intelligence and coding scores.
  - `GET /api/v1/key` shows the free tier (50 requests/day until 10 credits are bought, then 1000; 20/min). The limits are shared by all free models.
- **Failure handling:**
  - At most 3 attempts per message, one at a time.
  - Cooldowns honour `Retry-After` / `X-RateLimit-Reset`.
  - Account-wide errors (daily quota, 401, 402, network) pause the free route and go straight to paid.
  - The paid fallback has a daily request and cost cap.
- **From Iran:** OpenRouter may be blocked from the host. The code backs off (30 s → 10 min) and uses the paid API.

## Premium voice-to-text (`src/VoiceTranscriber.php`)
- **Provider:** GapGPT Whisper, `POST https://api.gapgpt.app/v1/audio/transcriptions` (multipart: `model=gapgpt/whisper-1`, `file`), billed per minute.
- **Order of checks:**
  1. paid entitlement on the server, **before** downloading
  2. duration ≤ 300 s and size ≤ 10 MB (estimated from size if Bale omits the duration)
  3. sniff the real file type (OggS, ID3, ftyp, RIFF, WebM)
  4. per-user limits: 20 per hour, 30 minutes per day
  5. one job per user, 3 globally
  6. duplicate update / file id
- **Afterwards:** usage is written to a table, and the temp file is always deleted.
- **Cheaper or free options** (not enabled): OpenAI `gpt-transcribe`, and Groq `whisper-large-v3` (free tier). Both use the same OpenAI-compatible endpoint, so only `base_url`, `model` and the key change.
