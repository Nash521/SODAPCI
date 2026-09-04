import test from 'node:test';
import assert from 'node:assert/strict';
import worker from '../src/index.js';

const env = {
  ALLOWED_ORIGIN: 'https://sodap-ci.example',
  FROM_EMAIL: 'forms@lasodapci.com',
  RESEND_API_KEY: 'test-key',
};

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

function applicationForm(fields = {}) {
  return contactForm({
    name: 'Ada Lovelace',
    email: 'ada@example.com',
    position: 'Agronomist',
    cv: new File(['cv'], 'cv.pdf', { type: 'application/pdf' }),
    coverLetter: new File(['letter'], 'lettre.docx', {
      type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    }),
    ...fields,
  });
}

function withFetch(handler) {
  const calls = [];
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async (url, init) => {
    calls.push({ url, init });
    return handler(url, init);
  };

  return {
    calls,
    restore() {
      globalThis.fetch = originalFetch;
    },
  };
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

test('rejects an invalid contact email address', async () => {
  const response = await worker.fetch(
    request('/api/contact', {
      method: 'POST',
      body: contactForm({
        name: 'Ada',
        email: 'not-an-email',
        subject: 'Demande de contact',
        message: 'Bonjour',
      }),
    }),
    env,
  );

  assert.equal(response.status, 400);
  assert.deepEqual(await response.json(), { message: 'Adresse email invalide.' });
});

test('rejects whitespace-only required contact fields', async () => {
  const response = await worker.fetch(
    request('/api/contact', {
      method: 'POST',
      body: contactForm({
        name: '   ',
        email: 'ada@example.com',
        subject: 'Demande de contact',
        message: 'Bonjour',
      }),
    }),
    env,
  );

  assert.equal(response.status, 400);
  assert.deepEqual(await response.json(), { message: 'Champs requis manquants.' });
});

test('valid multipart contact form calls Resend and responds 202', async () => {
  const fetchMock = withFetch(async () => (
    new Response(JSON.stringify({ id: 'email-id' }), { status: 200 })
  ));

  try {
    const response = await worker.fetch(
      request('/api/contact', {
        method: 'POST',
        body: contactForm({
          name: 'Ada',
          email: 'ada@example.com',
          subject: 'Demande de contact',
          message: 'Bonjour',
        }),
      }),
      env,
    );

    assert.equal(response.status, 202);
    assert.equal(fetchMock.calls.length, 1);
    assert.equal(fetchMock.calls[0].url, 'https://api.resend.com/emails');
    assert.equal(fetchMock.calls[0].init.method, 'POST');
    assert.equal(fetchMock.calls[0].init.headers.Authorization, 'Bearer test-key');
    assert.equal(fetchMock.calls[0].init.headers['Content-Type'], 'application/json');
    assert.equal(fetchMock.calls[0].init.headers['User-Agent'], 'sodapci-form-worker');
    const payload = JSON.parse(fetchMock.calls[0].init.body);
    assert.equal(payload.from, env.FROM_EMAIL);
    assert.deepEqual(payload.to, ['carriere@lasodapci.com']);
    assert.equal(payload.reply_to, 'ada@example.com');
    assert.equal(payload.subject, 'Contact \u2014 Demande de contact');
  } finally {
    fetchMock.restore();
  }
});

test('returns 400 for a candidature without a CV', async () => {
  const response = await worker.fetch(
    request('/api/candidature', {
      method: 'POST',
      body: applicationForm({ cv: '' }),
    }),
    env,
  );

  assert.equal(response.status, 400);
});

test('rejects an invalid candidature email address', async () => {
  const response = await worker.fetch(
    request('/api/candidature', {
      method: 'POST',
      body: applicationForm({ email: 'not-an-email' }),
    }),
    env,
  );

  assert.equal(response.status, 400);
  assert.deepEqual(await response.json(), { message: 'Adresse email invalide.' });
});

test('rejects whitespace-only required candidature fields', async () => {
  const response = await worker.fetch(
    request('/api/candidature', {
      method: 'POST',
      body: applicationForm({ position: '   ' }),
    }),
    env,
  );

  assert.equal(response.status, 400);
  assert.deepEqual(await response.json(), { message: 'Champs requis manquants.' });
});

test('returns 400 for a candidature attachment with an invalid type', async () => {
  const response = await worker.fetch(
    request('/api/candidature', {
      method: 'POST',
      body: applicationForm({
        cv: new Blob(['executable'], { type: 'application/x-msdownload' }),
      }),
    }),
    env,
  );

  assert.equal(response.status, 400);
});

test('returns 413 when a candidature attachment exceeds 5 MiB', async () => {
  const response = await worker.fetch(
    request('/api/candidature', {
      method: 'POST',
      body: applicationForm({
        coverLetter: new Blob([new Uint8Array(5 * 1024 * 1024 + 1)], {
          type: 'application/pdf',
        }),
      }),
    }),
    env,
  );

  assert.equal(response.status, 413);
});

test('accepts DOC and DOCX candidature attachments', async () => {
  const fetchMock = withFetch(async () => (
    new Response(JSON.stringify({ id: 'email-id' }), { status: 200 })
  ));

  try {
    const response = await worker.fetch(
      request('/api/candidature', {
        method: 'POST',
        body: applicationForm({
          cv: new File(['cv'], 'cv.doc', { type: 'application/msword' }),
          coverLetter: new File(['letter'], 'lettre.docx', {
            type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          }),
        }),
      }),
      env,
    );

    assert.equal(response.status, 202);
    assert.equal(fetchMock.calls.length, 1);
  } finally {
    fetchMock.restore();
  }
});

test('accepts a candidature attachment at the 5 MiB limit', async () => {
  const fetchMock = withFetch(async () => (
    new Response(JSON.stringify({ id: 'email-id' }), { status: 200 })
  ));

  try {
    const response = await worker.fetch(
      request('/api/candidature', {
        method: 'POST',
        body: applicationForm({
          cv: new File([new Uint8Array(5 * 1024 * 1024)], 'cv.pdf', {
            type: 'application/pdf',
          }),
        }),
      }),
      env,
    );

    assert.equal(response.status, 202);
    assert.equal(fetchMock.calls.length, 1);
  } finally {
    fetchMock.restore();
  }
});

test('valid candidature sends two base64 attachments and responds 202', async () => {
  const fetchMock = withFetch(async () => (
    new Response(JSON.stringify({ id: 'email-id' }), { status: 200 })
  ));

  try {
    const response = await worker.fetch(
      request('/api/candidature', {
        method: 'POST',
        body: applicationForm(),
      }),
      env,
    );

    assert.equal(response.status, 202);
    assert.equal(fetchMock.calls.length, 1);
    const payload = JSON.parse(fetchMock.calls[0].init.body);
    assert.equal(payload.subject, 'Candidature \u2014 Agronomist');
    assert.deepEqual(payload.to, ['carriere@lasodapci.com']);
    assert.equal(payload.reply_to, 'ada@example.com');
    assert.equal(payload.from, env.FROM_EMAIL);
    assert.deepEqual(payload.attachments, [
      { filename: 'cv.pdf', content: 'Y3Y=' },
      { filename: 'lettre.docx', content: 'bGV0dGVy' },
    ]);
  } finally {
    fetchMock.restore();
  }
});

test('accepts a honeypot submission without calling Resend', async () => {
  const fetchMock = withFetch(async () => {
    throw new Error('Resend must not be called for honeypot submissions');
  });

  try {
    const response = await worker.fetch(
      request('/api/contact', {
        method: 'POST',
        body: contactForm({
          name: 'Ada',
          email: 'ada@example.com',
          subject: 'Bonjour',
          message: 'Message',
          website: 'https://spam.example',
        }),
      }),
      env,
    );

    assert.equal(response.status, 202);
    assert.equal(fetchMock.calls.length, 0);
  } finally {
    fetchMock.restore();
  }
});

test('accepts a candidature honeypot submission without calling Resend', async () => {
  const fetchMock = withFetch(async () => {
    throw new Error('Resend must not be called for honeypot submissions');
  });

  try {
    const response = await worker.fetch(
      request('/api/candidature', {
        method: 'POST',
        body: applicationForm({ website: 'https://spam.example' }),
      }),
      env,
    );

    assert.equal(response.status, 202);
    assert.equal(fetchMock.calls.length, 0);
  } finally {
    fetchMock.restore();
  }
});

test('returns a public 502 response when Resend rejects a contact email', async () => {
  const fetchMock = withFetch(async () => (
    new Response(JSON.stringify({ message: 'invalid sender details' }), { status: 422 })
  ));

  try {
    const response = await worker.fetch(
      request('/api/contact', {
        method: 'POST',
        body: contactForm({
          name: 'Ada',
          email: 'ada@example.com',
          subject: 'Bonjour',
          message: 'Message',
        }),
      }),
      env,
    );

    assert.equal(response.status, 502);
    assert.deepEqual(await response.json(), { message: 'Envoi temporairement indisponible.' });
  } finally {
    fetchMock.restore();
  }
});

test('returns a public 502 response when Resend rejects a candidature email', async () => {
  const fetchMock = withFetch(async () => (
    new Response(JSON.stringify({ message: 'invalid sender details' }), { status: 422 })
  ));

  try {
    const response = await worker.fetch(
      request('/api/candidature', {
        method: 'POST',
        body: applicationForm(),
      }),
      env,
    );

    assert.equal(response.status, 502);
    assert.deepEqual(await response.json(), { message: 'Envoi temporairement indisponible.' });
  } finally {
    fetchMock.restore();
  }
});
