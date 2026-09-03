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

test('rejects an unauthorized Origin with 403', async () => {
  const response = await worker.fetch(
    new Request('https://worker.example/api/contact', {
      method: 'POST',
      headers: { Origin: 'https://attacker.example' },
    }),
    env,
  );

  assert.equal(response.status, 403);
});

test('returns 404 for an unknown route', async () => {
  const response = await worker.fetch(request('/api/unknown'), env);

  assert.equal(response.status, 404);
});

test('returns 400 for an incomplete contact form', async () => {
  const response = await worker.fetch(
    request('/api/contact', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({}),
    }),
    env,
  );

  assert.equal(response.status, 400);
  assert.deepEqual(await response.json(), { message: 'Champs requis manquants.' });
});

test('valid contact form calls Resend and responds 202', { skip: 'Implemented in Task 2' }, async () => {
  const response = await worker.fetch(
    request('/api/contact', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: 'Ada', email: 'ada@example.com', message: 'Bonjour' }),
    }),
    { ...env, RESEND_API_KEY: 'test-key' },
  );

  assert.equal(response.status, 202);
});
