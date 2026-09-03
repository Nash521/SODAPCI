function corsHeaders(origin) {
  return {
    'Access-Control-Allow-Origin': origin,
    'Access-Control-Allow-Methods': 'POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type',
    Vary: 'Origin',
  };
}

function jsonResponse(body, status, origin) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': 'application/json',
      ...corsHeaders(origin),
    },
  });
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
    if (url.pathname !== '/api/contact') {
      return jsonResponse({ message: 'Route introuvable.' }, 404, origin);
    }

    if (request.method !== 'POST') {
      return jsonResponse({ message: 'Méthode non autorisée.' }, 405, origin);
    }

    return jsonResponse({ message: 'Champs requis manquants.' }, 400, origin);
  },
};
