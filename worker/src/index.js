const RECIPIENT = 'carriere@lasodapci.com';
const ALLOWED_ATTACHMENT_EXTENSIONS = new Map([
  ['application/pdf', '.pdf'],
  ['application/msword', '.doc'],
  ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', '.docx'],
]);
const MAX_ATTACHMENT_SIZE = 5 * 1024 * 1024;
const MAX_ATTACHMENT_FILENAME_LENGTH = 128;
const MAX_CONTACT_BODY_SIZE = 64 * 1024;
const MAX_APPLICATION_BODY_SIZE = 11 * 1024 * 1024;
const MAX_NAME_LENGTH = 120;
const MAX_EMAIL_LENGTH = 254;
const MAX_PHONE_LENGTH = 40;
const MAX_SUBJECT_LENGTH = 120;
const MAX_POSITION_LENGTH = 120;
const MAX_MESSAGE_LENGTH = 5000;
const GENERIC_ATTACHMENT_TYPES = new Set(['', 'application/octet-stream']);
const DOCUMENT_SIGNATURES = new Map([
  ['.doc', new Uint8Array([0xd0, 0xcf, 0x11, 0xe0, 0xa1, 0xb1, 0x1a, 0xe1])],
  ['.docx', new Uint8Array([0x50, 0x4b, 0x03, 0x04])],
]);
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
  const declaredExtension = ALLOWED_ATTACHMENT_EXTENSIONS.get(file.type);

  if (
    !filename
    || filename.length > MAX_ATTACHMENT_FILENAME_LENGTH
    || (GENERIC_ATTACHMENT_TYPES.has(file.type)
      ? !DOCUMENT_SIGNATURES.has(extension)
      : declaredExtension !== extension)
  ) {
    return null;
  }

  return filename;
}

function unsafeHeaderValue(value) {
  return CONTROL_CHARACTERS.test(value);
}

function stringExceedsLimit(value, maxLength) {
  return value.length > maxLength;
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

function hasExpectedDocumentSignature(filename, bytes) {
  const extension = filename.slice(filename.lastIndexOf('.')).toLowerCase();
  const signature = DOCUMENT_SIGNATURES.get(extension);

  return !signature || (
    bytes.length >= signature.length
    && signature.every((value, index) => bytes[index] === value)
  );
}

function attachment({ filename, bytes }) {
  return { filename, content: toBase64(bytes) };
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

function applicationPayload(env, candidate, cv, coverLetter) {
  const { name, email, position } = candidate;
  return {
    from: env.FROM_EMAIL,
    to: [RECIPIENT],
    reply_to: email,
    subject: `Candidature — ${position}`,
    text: `Nom : ${name}\nEmail : ${email}\nPoste : ${position}`,
    html: `<h1>Nouvelle candidature</h1><p><strong>Nom :</strong> ${escapeHtml(name)}</p><p><strong>Email :</strong> ${escapeHtml(email)}</p><p><strong>Poste :</strong> ${escapeHtml(position)}</p>`,
    attachments: [attachment(cv), attachment(coverLetter)],
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

async function validatedAttachment(file, origin) {
  const error = invalidAttachmentResponse(file, origin);
  if (error) return { error };

  const filename = attachmentFilename(file);
  const bytes = new Uint8Array(await file.arrayBuffer());
  if (!hasExpectedDocumentSignature(filename, bytes)) {
    return { error: jsonResponse({ message: 'Type de fichier non autorisé.' }, 400, origin) };
  }

  return { filename, bytes };
}

async function handleContact(form, env, origin) {
  if (nonblank(form, 'website')) {
    return successResponse(origin);
  }

  const values = {
    name: stringValue(form, 'name'),
    email: stringValue(form, 'email'),
    phone: stringValue(form, 'phone'),
    subject: stringValue(form, 'subject'),
    message: stringValue(form, 'message'),
  };
  if (
    stringExceedsLimit(values.name, MAX_NAME_LENGTH)
    || stringExceedsLimit(values.email, MAX_EMAIL_LENGTH)
    || stringExceedsLimit(values.phone, MAX_PHONE_LENGTH)
    || stringExceedsLimit(values.subject, MAX_SUBJECT_LENGTH)
    || stringExceedsLimit(values.message, MAX_MESSAGE_LENGTH)
  ) {
    return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
  }
  const contact = {
    name: values.name.trim(),
    email: values.email.trim(),
    phone: values.phone.trim(),
    subject: values.subject.trim(),
    message: values.message.trim(),
  };
  if (!contact.name || !contact.email || !contact.subject || !contact.message) {
    return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
  }
  if (!EMAIL_PATTERN.test(contact.email)) {
    return jsonResponse({ message: 'Adresse email invalide.' }, 400, origin);
  }
  if (unsafeHeaderValue(values.subject)) {
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

  const values = {
    name: stringValue(form, 'name'),
    email: stringValue(form, 'email'),
    position: stringValue(form, 'position'),
  };
  if (
    stringExceedsLimit(values.name, MAX_NAME_LENGTH)
    || stringExceedsLimit(values.email, MAX_EMAIL_LENGTH)
    || stringExceedsLimit(values.position, MAX_POSITION_LENGTH)
  ) {
    return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
  }
  const candidate = {
    name: values.name.trim(),
    email: values.email.trim(),
    position: values.position.trim(),
  };
  if (!candidate.name || !candidate.email || !candidate.position) {
    return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
  }
  if (!EMAIL_PATTERN.test(candidate.email)) {
    return jsonResponse({ message: 'Adresse email invalide.' }, 400, origin);
  }
  if (unsafeHeaderValue(values.position)) {
    return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
  }

  const cv = form.get('cv');
  const coverLetter = form.get('coverLetter');
  const validatedCv = await validatedAttachment(cv, origin);
  if (validatedCv.error) return validatedCv.error;
  const validatedCoverLetter = await validatedAttachment(coverLetter, origin);
  if (validatedCoverLetter.error) return validatedCoverLetter.error;

  const sent = await sendEmail(
    env,
    applicationPayload(env, candidate, validatedCv, validatedCoverLetter),
  );
  return sent
    ? successResponse(origin)
    : jsonResponse({ message: 'Envoi temporairement indisponible.' }, 502, origin);
}

async function boundedFormData(request, maxBodySize) {
  const contentLength = Number(request.headers.get('Content-Length'));
  if (Number.isFinite(contentLength) && contentLength > maxBodySize) {
    return { tooLarge: true };
  }

  if (!request.body) {
    return { form: await request.formData() };
  }

  let size = 0;
  let tooLarge = false;
  const reader = request.body.getReader();
  const body = new ReadableStream({
    async pull(controller) {
      try {
        const { done, value } = await reader.read();
        if (done) {
          controller.close();
          return;
        }

        size += value.byteLength;
        if (size > maxBodySize) {
          tooLarge = true;
          controller.error(new RangeError('Request body exceeds its size limit.'));
          await reader.cancel();
          return;
        }

        controller.enqueue(value);
      } catch (error) {
        controller.error(error);
      }
    },
    cancel(reason) {
      return reader.cancel(reason);
    },
  });

  try {
    return { form: await new Request(request, { body, duplex: 'half' }).formData() };
  } catch (error) {
    if (tooLarge) return { tooLarge: true };
    throw error;
  }
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
      const parsed = await boundedFormData(
        request,
        url.pathname === '/api/contact' ? MAX_CONTACT_BODY_SIZE : MAX_APPLICATION_BODY_SIZE,
      );
      if (parsed.tooLarge) {
        return jsonResponse({ message: 'Requête trop volumineuse.' }, 413, origin);
      }
      form = parsed.form;
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
