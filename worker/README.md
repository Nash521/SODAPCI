# SODAP-CI form email worker

This Cloudflare Worker provides the form backend for the SODAP-CI site. It sends contact and job-application form submissions through Resend to the fixed recipient `carriere@lasodapci.com`.

## Local development

From the repository root, copy the example variables file and edit the copy with local values:

```powershell
Copy-Item .dev.vars.example .dev.vars
```

The resulting `worker/.dev.vars` is local-only and must never be committed to Git. It contains the Resend API key, sender address, and local allowed origin. Start the Worker locally with:

```powershell
cd worker
npm install
npx wrangler dev
```

The example file contains placeholders only. Do not add secrets to `wrangler.toml`.

## Production deployment

Install dependencies and run the worker tests before configuring production secrets:

```powershell
cd worker
npm install
npm test
```

Store production values as Cloudflare Worker secrets, never in Git:

```powershell
npx wrangler secret put RESEND_API_KEY
npx wrangler secret put FROM_EMAIL
npx wrangler secret put ALLOWED_ORIGIN
npm run deploy
```

`FROM_EMAIL` must be a sender address verified in Resend. Associate the Worker with `https://votre-domaine/api/*`, using the same origin as the site, and set `ALLOWED_ORIGIN` to that site origin. Add an explicit Cloudflare rate-limit rule protecting `POST /api/*` before going live.

## Form limits and formats

- Each attachment is limited to 5 MiB (5 × 1024 × 1024 bytes).
- Accepted attachment formats are PDF, DOC, and DOCX.
- Every form email is sent to `carriere@lasodapci.com`.

Keep all API keys and other environment values out of Git. Commit only `worker/.dev.vars.example`, never `worker/.dev.vars`.
