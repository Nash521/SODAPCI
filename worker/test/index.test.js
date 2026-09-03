import test from 'node:test';
import assert from 'node:assert/strict';
import worker from '../src/index.js';

const env = { ALLOWED_ORIGIN: 'https://sodap-ci.example' };

function request(path, options = {}) {
  return new Request(`https://worker.example${path}`, {
    ...options,
    headers: {
      Origin: env.ALLOWED_ORIGIN,
      ...options.headers,
    },
  });
}

function contactForm(fields = {}) {
  const form = new FormData();
  for (const [name, value] of Object.entries(fields)) {
    form.set(name, value);
  }
  return form;
}

test('rejects an unauthorized Origin with 403 without granting CORS access', async () => {
  const response = await worker.fetch(
    new Request('https://worker.example/api/contact', {
      method: 'POST',
      headers: { Origin: 'https://attacker.example' },
    }),
    env,
  );

  assert.equal(response.status, 403);
  assert.equal(response.headers.get('Access-Control-Allow-Origin'), 'null');
  assert.equal(response.headers.get('Vary'), 'Origin');
});

test('returns authorized CORS headers for an OPTIONS preflight', async () => {
  const response = await worker.fetch(
    request('/api/contact', { method: 'OPTIONS' }),
    env,
  );

  assert.equal(response.status, 204);
  assert.equal(response.headers.get('Access-Control-Allow-Origin'), env.ALLOWED_ORIGIN);
  assert.equal(response.headers.get('Access-Control-Allow-Methods'), 'POST, OPTIONS');
  assert.equal(response.headers.get('Access-Control-Allow-Headers'), 'Content-Type');
  assert.equal(response.headers.get('Vary'), 'Origin');
});

test('returns authorized CORS headers for JSON responses', async () => {
  const response = await worker.fetch(request('/api/unknown'), env);

  assert.equal(response.status, 404);
  assert.equal(response.headers.get('Access-Control-Allow-Origin'), env.ALLOWED_ORIGIN);
  assert.equal(response.headers.get('Vary'), 'Origin');
});

test('returns 404 for an unknown route', async () => {
  const response = await worker.fetch(request('/api/unknown'), env);

  assert.equal(response.status, 404);
});

test('returns 404 for /api/candidature until Task 2', async () => {
  const response = await worker.fetch(
    request('/api/candidature', {
      method: 'POST',
      body: contactForm({ name: 'Ada' }),
    }),
    env,
  );

  assert.equal(response.status, 404);
});

test('returns 400 for an incomplete multipart contact form', async () => {
  const response = await worker.fetch(
    request('/api/contact', {
      method: 'POST',
      body: contactForm({ name: 'Ada' }),
    }),
    env,
  );

  assert.equal(response.status, 400);
  assert.deepEqual(await response.json(), { message: 'Champs requis manquants.' });
});

test('valid multipart contact form calls Resend and responds 202', { skip: 'Implemented in Task 2' }, async () => {
  const calls = [];
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async (url, init) => {
    calls.push({ url, init });
    return new Response(JSON.stringify({ id: 'email-id' }), { status: 200 });
  };

  try {
    const response = await worker.fetch(
      request('/api/contact', {
        method: 'POST',
        body: contactForm({ name: 'Ada', email: 'ada@example.com', message: 'Bonjour' }),
      }),
      {
        ...env,
        RESEND_API_KEY: 'test-key',
        CONTACT_RECIPIENT: 'forms@sodap-ci.example',
      },
    );

    assert.equal(response.status, 202);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, 'https://api.resend.com/emails');
    const payload = JSON.parse(calls[0].init.body);
    assert.deepEqual(payload.to, ['forms@sodap-ci.example']);
    assert.equal(payload.reply_to, 'ada@example.com');
  } finally {
    globalThis.fetch = originalFetch;
  }
});
