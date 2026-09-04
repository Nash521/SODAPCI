const RECIPIENT = 'carriere@lasodapci.com';
const ALLOWED_ATTACHMENT_EXTENSIONS = new Map([
  ['application/pdf', '.pdf'],
  ['application/msword', '.doc'],
  ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', '.docx'],
]);
const MAX_ATTACHMENT_SIZE = 5 * 1024 * 1024;
const MAX_ATTACHMENT_FILENAME_LENGTH = 128;
// Keep user-supplied mail-header values short enough for reliable delivery/display.
const MAX_SUBJECT_LENGTH = 160;
const MAX_POSITION_LENGTH = 120;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const CONTROL_CHARACTERS = /[\u0000-\u001F\u007F-\u009F]/;
const CONTROL_CHARACTERS_GLOBAL = /[\u0000-\u001F\u007F-\u009F]/g;

function corsHeaders(origin) {
  const headers = { Vary: 'Origin' };
  if (!origin || origin === 'null') return headers;

  return {
    ...headers,
    'Access-Control-Allow-Origin': origin,
    'Access-Control-Allow-Methods': 'POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type',
  };
}

function jsonResponse(body, status, origin, headers = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': 'application/json',
      ...corsHeaders(origin),
      ...(status === 405 ? { Allow: 'POST, OPTIONS' } : {}),
      ...headers,
    },
  });
}

function successResponse(origin) {
  return jsonResponse({ success: true, message: 'Message envoyé.' }, 202, origin);
}

function stringValue(form, field) {
  const value = form.get(field);
  return typeof value === 'string' ? value : '';
}

function nonblank(form, field) {
  return stringValue(form, field).trim();
}

function isFile(value) {
  return value
    && typeof value === 'object'
    && typeof value.name === 'string'
    && typeof value.type === 'string'
    && typeof value.size === 'number'
    && typeof value.arrayBuffer === 'function';
}

function attachmentFilename(file) {
  const filename = file.name
    .split(/[\\/]+/)
    .pop()
    .replace(CONTROL_CHARACTERS_GLOBAL, '')
    .trim();
  const extension = filename.slice(filename.lastIndexOf('.')).toLowerCase();

  if (
    !filename
    || filename.length > MAX_ATTACHMENT_FILENAME_LENGTH
    || ALLOWED_ATTACHMENT_EXTENSIONS.get(file.type) !== extension
  ) {
    return null;
  }

  return filename;
}

function unsafeHeaderValue(value, maxLength) {
  return value.length > maxLength || CONTROL_CHARACTERS.test(value);
}

function escapeHtml(value) {
  return value.replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  })[character]);
}

function toBase64(bytes) {
  let binary = '';
  const chunkSize = 0x8000;

  for (let offset = 0; offset < bytes.length; offset += chunkSize) {
    binary += String.fromCharCode(...bytes.subarray(offset, offset + chunkSize));
  }

  return btoa(binary);
}

async function attachment(file) {
  const bytes = new Uint8Array(await file.arrayBuffer());
  return { filename: attachmentFilename(file), content: toBase64(bytes) };
}

async function sendEmail(env, payload) {
  if (!env.RESEND_API_KEY || !env.FROM_EMAIL) {
    return false;
  }

  try {
    const response = await fetch('https://api.resend.com/emails', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${env.RESEND_API_KEY}`,
        'Content-Type': 'application/json',
        'User-Agent': 'sodapci-form-worker',
      },
      body: JSON.stringify(payload),
    });
    return response.ok;
  } catch {
    return false;
  }
}

function contactPayload(env, contact) {
  const { name, email, phone, subject, message } = contact;
  return {
    from: env.FROM_EMAIL,
    to: [RECIPIENT],
    reply_to: email,
    subject: `Contact — ${subject}`,
    text: `Nom : ${name}\nEmail : ${email}${phone ? `\nTéléphone : ${phone}` : ''}\nSujet : ${subject}\n\n${message}`,
    html: `<h1>Nouvelle prise de contact</h1><p><strong>Nom :</strong> ${escapeHtml(name)}</p><p><strong>Email :</strong> ${escapeHtml(email)}</p>${phone ? `<p><strong>Téléphone :</strong> ${escapeHtml(phone)}</p>` : ''}<p><strong>Sujet :</strong> ${escapeHtml(subject)}</p><p>${escapeHtml(message).replace(/\n/g, '<br>')}</p>`,
  };
}

async function applicationPayload(env, candidate, cv, coverLetter) {
  const { name, email, position } = candidate;
  return {
    from: env.FROM_EMAIL,
    to: [RECIPIENT],
    reply_to: email,
    subject: `Candidature — ${position}`,
    text: `Nom : ${name}\nEmail : ${email}\nPoste : ${position}`,
    html: `<h1>Nouvelle candidature</h1><p><strong>Nom :</strong> ${escapeHtml(name)}</p><p><strong>Email :</strong> ${escapeHtml(email)}</p><p><strong>Poste :</strong> ${escapeHtml(position)}</p>`,
    attachments: [await attachment(cv), await attachment(coverLetter)],
  };
}

function invalidAttachmentResponse(file, origin) {
  if (!isFile(file) || !file.size) {
    return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
  }
  if (file.size > MAX_ATTACHMENT_SIZE) {
    return jsonResponse({ message: 'Fichier trop volumineux.' }, 413, origin);
  }
  if (!attachmentFilename(file)) {
    return jsonResponse({ message: 'Type de fichier non autorisé.' }, 400, origin);
  }
  return null;
}

async function handleContact(form, env, origin) {
  if (nonblank(form, 'website')) {
    return successResponse(origin);
  }

  const subject = stringValue(form, 'subject');
  const contact = {
    name: nonblank(form, 'name'),
    email: nonblank(form, 'email'),
    phone: nonblank(form, 'phone'),
    subject: subject.trim(),
    message: nonblank(form, 'message'),
  };
  if (!contact.name || !contact.email || !contact.subject || !contact.message) {
    return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
  }
  if (!EMAIL_PATTERN.test(contact.email)) {
    return jsonResponse({ message: 'Adresse email invalide.' }, 400, origin);
  }
  if (unsafeHeaderValue(subject, MAX_SUBJECT_LENGTH)) {
    return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
  }

  const sent = await sendEmail(env, contactPayload(env, contact));
  return sent
    ? successResponse(origin)
    : jsonResponse({ message: 'Envoi temporairement indisponible.' }, 502, origin);
}

async function handleApplication(form, env, origin) {
  if (nonblank(form, 'website')) {
    return successResponse(origin);
  }

  const position = stringValue(form, 'position');
  const candidate = {
    name: nonblank(form, 'name'),
    email: nonblank(form, 'email'),
    position: position.trim(),
  };
  if (!candidate.name || !candidate.email || !candidate.position) {
    return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
  }
  if (!EMAIL_PATTERN.test(candidate.email)) {
    return jsonResponse({ message: 'Adresse email invalide.' }, 400, origin);
  }
  if (unsafeHeaderValue(position, MAX_POSITION_LENGTH)) {
    return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
  }

  const cv = form.get('cv');
  const coverLetter = form.get('coverLetter');
  const cvError = invalidAttachmentResponse(cv, origin);
  const coverLetterError = invalidAttachmentResponse(coverLetter, origin);
  if (cvError) return cvError;
  if (coverLetterError) return coverLetterError;

  const sent = await sendEmail(
    env,
    await applicationPayload(env, candidate, cv, coverLetter),
  );
  return sent
    ? successResponse(origin)
    : jsonResponse({ message: 'Envoi temporairement indisponible.' }, 502, origin);
}

export default {
  async fetch(request, env) {
    const origin = request.headers.get('Origin');

    if (origin !== env.ALLOWED_ORIGIN) {
      return jsonResponse({ message: 'Origin non autorisée.' }, 403, 'null');
    }

    if (request.method === 'OPTIONS') {
      return new Response(null, {
        status: 204,
        headers: {
          ...corsHeaders(origin),
          'Content-Type': 'application/json',
        },
      });
    }

    const url = new URL(request.url);
    if (url.pathname !== '/api/contact' && url.pathname !== '/api/candidature') {
      return jsonResponse({ message: 'Route introuvable.' }, 404, origin);
    }

    if (request.method !== 'POST') {
      return jsonResponse({ message: 'Méthode non autorisée.' }, 405, origin);
    }

    let form;
    try {
      form = await request.formData();
    } catch {
      return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
    }

    try {
      return await (url.pathname === '/api/contact'
        ? handleContact(form, env, origin)
        : handleApplication(form, env, origin));
    } catch {
      return jsonResponse({ message: 'Envoi temporairement indisponible.' }, 502, origin);
    }
  },
};
