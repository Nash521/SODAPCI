import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import vm from "node:vm";

const failures = [];
const scriptPath = "js/forms.js";

function requiredSnippets(script) {
  return [
    "[data-email-form]",
    "new FormData(form)",
    'Accept: "application/json"',
    "response.ok",
    "form.reset()",
    'status.setAttribute("role", "alert")',
  ];
}

function makeClassList() {
  const values = new Set();
  return {
    add: (...names) => names.forEach((name) => values.add(name)),
    remove: (...names) => names.forEach((name) => values.delete(name)),
    contains: (name) => values.has(name),
  };
}

function makeForm() {
  const status = {
    classList: makeClassList(),
    attributes: {},
    textContent: "Old status",
    setAttribute(name, value) {
      this.attributes[name] = value;
    },
  };
  const button = { disabled: false, textContent: "Envoyer" };
  const form = {
    action: "/api/send-form.php",
    resetCount: 0,
    listener: null,
    querySelector(selector) {
      if (selector === "[data-form-status]") return status;
      if (selector === 'button[type="submit"]') return button;
      return null;
    },
    addEventListener(type, listener) {
      if (type === "submit") this.listener = listener;
    },
    reset() {
      this.resetCount += 1;
    },
    dispatchSubmit() {
      const event = {
        defaultPrevented: false,
        preventDefault() {
          this.defaultPrevented = true;
        },
      };
      this.listener(event);
      return event;
    },
    status,
    button,
  };
  return form;
}

function makeResponse(body, ok = true) {
  return {
    ok,
    text: () => Promise.resolve(body),
  };
}

async function loadScript({ forms, fetch, FormData = class {} } = {}) {
  const script = readFileSync(scriptPath, "utf8");
  const context = {
    document: {
      readyState: "complete",
      querySelectorAll: () => forms,
    },
    fetch,
    FormData,
    Promise,
    console,
  };
  vm.runInNewContext(script, context, { filename: scriptPath });
}

async function settle() {
  await new Promise((resolve) => setImmediate(resolve));
}

async function runBehaviorTests() {
  {
    const form = makeForm();
    const calls = [];
    class TestFormData {
      constructor(value) {
        this.value = value;
      }
    }
    await loadScript({
      forms: [form],
      FormData: TestFormData,
      fetch: (url, options) => {
        calls.push({ url, options });
        return Promise.resolve(makeResponse('{"ok":true,"message":"Envoyé"}'));
      },
    });
    const event = form.dispatchSubmit();
    await settle();
    assert.equal(event.defaultPrevented, true, "supported browser submission is intercepted");
    assert.equal(calls.length, 1, "one request is sent");
    assert.equal(calls[0].options.method, "POST");
    assert.equal(calls[0].options.headers.Accept, "application/json");
    assert.equal(calls[0].options.body.value, form);
    assert.equal(form.resetCount, 1, "valid JSON success resets the form");
    assert.equal(form.status.textContent, "Envoyé");
    assert.equal(form.status.attributes.role, "alert");
    assert.equal(form.status.classList.contains("text-green-600"), true);
    assert.equal(form.button.disabled, false, "button is restored after success");
    assert.equal(form.button.textContent, "Envoyer");
  }

  for (const testCase of [
    { name: "HTTP error", response: makeResponse('{"message":"Serveur indisponible"}', false), message: "Serveur indisponible" },
    { name: "ok:false", response: makeResponse('{"ok":false,"message":"Refusé"}'), message: "Refusé" },
    { name: "malformed 2xx", response: makeResponse("not json"), message: "Une erreur est survenue. Veuillez réessayer." },
    { name: "empty 2xx", response: makeResponse(""), message: "Une erreur est survenue. Veuillez réessayer." },
  ]) {
    const form = makeForm();
    await loadScript({ forms: [form], fetch: () => Promise.resolve(testCase.response) });
    form.dispatchSubmit();
    await settle();
    assert.equal(form.resetCount, 0, `${testCase.name} keeps fields`);
    assert.equal(form.status.textContent, testCase.message, `${testCase.name} shows an error`);
    assert.equal(form.status.classList.contains("text-red-600"), true);
    assert.equal(form.button.disabled, false, `${testCase.name} restores button`);
  }

  {
    const form = makeForm();
    await loadScript({ forms: [form], fetch: () => Promise.reject(new Error("Connexion impossible")) });
    form.dispatchSubmit();
    await settle();
    assert.equal(form.resetCount, 0, "network failure keeps fields");
    assert.equal(form.status.textContent, "Connexion impossible");
    assert.equal(form.button.disabled, false, "network failure restores button");
  }

  {
    const form = makeForm();
    let resolveRequest;
    let requestCount = 0;
    const responsePromise = new Promise((resolve) => { resolveRequest = resolve; });
    await loadScript({
      forms: [form],
      fetch: () => {
        requestCount += 1;
        return responsePromise;
      },
    });
    form.dispatchSubmit();
    const duplicateEvent = form.dispatchSubmit();
    assert.equal(duplicateEvent.defaultPrevented, true, "duplicate submission is blocked");
    assert.equal(requestCount, 1, "duplicate submission does not send another request");
    assert.equal(form.button.disabled, true, "button stays disabled while pending");
    resolveRequest(makeResponse('{"ok":true}'));
    await settle();
    assert.equal(form.button.disabled, false, "pending button is restored");
  }

  {
    const first = makeForm();
    const second = makeForm();
    let requestCount = 0;
    await loadScript({
      forms: [first, second],
      fetch: () => {
        requestCount += 1;
        return Promise.resolve(makeResponse('{"ok":true}'));
      },
    });
    first.dispatchSubmit();
    second.dispatchSubmit();
    await settle();
    assert.equal(requestCount, 2, "multiple forms submit independently");
    assert.equal(first.resetCount, 1);
    assert.equal(second.resetCount, 1);
  }

  {
    const form = makeForm();
    await loadScript({ forms: [form], fetch: undefined });
    const event = form.dispatchSubmit();
    assert.equal(event.defaultPrevented, false, "unsupported browser keeps native submission");
    assert.equal(form.button.disabled, false);
  }
}

if (!existsSync(scriptPath)) {
  failures.push(`Missing form script: ${scriptPath}`);
} else {
  const script = readFileSync(scriptPath, "utf8");
  for (const snippet of requiredSnippets(script)) {
    if (!script.includes(snippet)) {
      failures.push(`Missing required snippet: ${snippet}`);
    }
  }

  if (failures.length === 0) {
    try {
      await runBehaviorTests();
    } catch (error) {
      failures.push(`Behavior test failed: ${error.message}`);
    }
  }
}

if (failures.length > 0) {
  console.error("Form script verification failed:");
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log("Form script verification passed.");
