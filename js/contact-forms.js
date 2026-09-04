(function () {
    "use strict";

    function setStatus(status, message) {
        if (status) {
            status.textContent = message;
        }
    }

    function getServerError(payload) {
        if (!payload || typeof payload !== "object") {
            return "";
        }

        if (typeof payload.error === "string") {
            return payload.error;
        }

        if (payload.error && typeof payload.error.message === "string") {
            return payload.error.message;
        }

        return typeof payload.message === "string" ? payload.message : "";
    }

    function setButtonText(button, text) {
        if (!button) {
            return;
        }

        if (button.tagName === "INPUT") {
            button.value = text;
            return;
        }

        button.textContent = text;
    }

    document.querySelectorAll("form[data-form-endpoint]").forEach(function (form) {
        form.addEventListener("submit", async function (event) {
            event.preventDefault();

            const status = form.querySelector("[data-form-status]");
            const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');

            if (!form.reportValidity()) {
                return;
            }

            for (const fileInput of form.querySelectorAll("[data-max-file-bytes]")) {
                const maxBytes = Number(fileInput.dataset.maxFileBytes);
                const file = fileInput.files && fileInput.files[0];

                if (file && Number.isFinite(maxBytes) && file.size > maxBytes) {
                    setStatus(status, "Le fichier sélectionné dépasse la taille maximale autorisée de 5 Mo.");
                    return;
                }
            }

            const originalDisabled = submitButton ? submitButton.disabled : false;
            const originalText = submitButton ? (submitButton.tagName === "INPUT" ? submitButton.value : submitButton.textContent) : "";

            setStatus(status, "Envoi en cours…");
            if (submitButton) {
                submitButton.disabled = true;
                setButtonText(submitButton, "Envoi en cours…");
            }

            try {
                const response = await fetch(form.dataset.formEndpoint, {
                    method: "POST",
                    body: new FormData(form),
                    headers: { Accept: "application/json" },
                });
                let payload = null;

                try {
                    payload = await response.json();
                } catch (_) {
                    // A non-JSON response is handled with the generic public error below.
                }

                if (!response.ok) {
                    setStatus(status, getServerError(payload) || "Une erreur est survenue lors de l’envoi. Veuillez réessayer.");
                    return;
                }

                form.reset();
                setStatus(status, "Votre demande a bien été envoyée.");
            } catch (_) {
                setStatus(status, "Une erreur est survenue lors de l’envoi. Veuillez réessayer.");
            } finally {
                if (submitButton) {
                    submitButton.disabled = originalDisabled;
                    setButtonText(submitButton, originalText);
                }
            }
        });
    });
})();
