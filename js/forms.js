(function () {
  "use strict";

  function messageFrom(payload, fallback) {
    if (payload && typeof payload.message === "string" && payload.message.trim()) {
      return payload.message.trim();
    }

    if (payload && typeof payload.error === "string" && payload.error.trim()) {
      return payload.error.trim();
    }

    return fallback;
  }

  function parseResponse(response) {
    return response.text().then(function (body) {
      if (!body.trim()) {
        return null;
      }

      try {
        return JSON.parse(body);
      } catch (error) {
        return null;
      }
    });
  }

  function setStatus(status, message, isError) {
    if (!status) {
      return;
    }

    status.setAttribute("role", "alert");
    status.classList.remove("hidden", "text-green-600", "text-red-600");
    status.classList.add(isError ? "text-red-600" : "text-green-600");
    status.textContent = message;
  }

  function resetStatus(status) {
    if (!status) {
      return;
    }

    status.classList.add("hidden");
    status.classList.remove("text-green-600", "text-red-600");
    status.textContent = "";
  }

  function initForm(form) {
    var status = form.querySelector("[data-form-status]");
    var submitButton = form.querySelector('button[type="submit"]');

    form.addEventListener("submit", function (event) {
      var runtimeSupported = typeof fetch === "function" &&
        typeof FormData === "function" &&
        typeof Promise === "function";

      if (!runtimeSupported) {
        return;
      }

      if (form._emailFormSubmitting) {
        event.preventDefault();
        return;
      }

      event.preventDefault();
      form._emailFormSubmitting = true;
      resetStatus(status);

      var originalLabel = submitButton ? submitButton.textContent : "";
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = "Envoi en cours…";
      }

      function cleanup() {
        form._emailFormSubmitting = false;
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = originalLabel;
        }
      }

      function showFailure(error) {
        setStatus(status, error && error.message ? error.message : "Une erreur est survenue. Veuillez réessayer.", true);
      }

      var request;
      try {
        request = fetch(form.action, {
          method: "POST",
          headers: {
            Accept: "application/json",
          },
          body: new FormData(form),
        });
      } catch (error) {
        showFailure(error);
        cleanup();
        return;
      }

      if (!request || typeof request.then !== "function") {
        showFailure(new Error("Une erreur est survenue. Veuillez réessayer."));
        cleanup();
        return;
      }

      request
        .then(function (response) {
          if (!response || typeof response.ok !== "boolean" || typeof response.text !== "function") {
            throw new Error("Une erreur est survenue. Veuillez réessayer.");
          }

          return parseResponse(response).then(function (payload) {
            if (!response.ok) {
              throw new Error(messageFrom(payload, "Une erreur est survenue. Veuillez réessayer."));
            }

            if (!payload || payload.ok !== true) {
              throw new Error(messageFrom(payload, "Une erreur est survenue. Veuillez réessayer."));
            }

            return payload;
          });
        })
        .then(function (payload) {
          form.reset();
          setStatus(status, messageFrom(payload, "Votre message a bien été envoyé."), false);
        }, showFailure)
        .then(cleanup, cleanup);
    });
  }

  function init() {
    document.querySelectorAll("[data-email-form]").forEach(initForm);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
