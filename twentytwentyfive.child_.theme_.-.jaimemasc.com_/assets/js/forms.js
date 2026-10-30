document.addEventListener("DOMContentLoaded", () => {
	const forms = document.querySelectorAll(".js-mohfam-form");

	forms.forEach((form) => {
		const status = form.querySelector(".mohfam-form-status");
		const submitButton = form.querySelector('[type="submit"]');
		const originalButtonText = submitButton ? submitButton.textContent : "";
		const turnstileElement = form.querySelector(".mohfam-turnstile");
		let turnstileWidgetId = null;
		const backup = " Please call +1 (818) 818-1181 if you need help.";
		let session = null;
		async function refreshSession() {
			try {
				const request = await fetch(mohfamForms.ajaxUrl, {
					method: "POST",
					credentials: "same-origin",
					cache: "no-store",
					body: new URLSearchParams({ action: "mohfam_form_session" }),
				});
				const response = await request.json();
				if (!request.ok || !response.success || !response.data?.form_token || !response.data?.nonce) {
					return null;
				}
				return response.data;
			} catch {
				return null;
			}
		}
		let sessionReady = refreshSession().then((value) => { session = value; });

		if (
			mohfamForms.turnstileEnabled &&
			turnstileElement &&
			window.turnstile
		) {
			turnstileWidgetId = window.turnstile.render(turnstileElement, {
				sitekey: mohfamForms.turnstileSiteKey,
				action: "mohfam_form",
				theme: "auto",
			});
		}

		form.addEventListener("submit", async (event) => {
			event.preventDefault();

			if (!form.reportValidity() || !submitButton) {
				return;
			}

			const turnstileResponse = form.querySelector(
				'[name="cf-turnstile-response"]',
			);

			if (
				mohfamForms.turnstileEnabled &&
				(!turnstileResponse || !turnstileResponse.value)
			) {
				status.className = "mohfam-form-status is-error";
				status.textContent =
					"Please complete the security verification and try again." + backup;
				return;
			}

			const formData = new FormData(form);
			formData.append("action", "mohfam_submit_form");
			formData.append("page_url", window.location.href);

			submitButton.disabled = true;
			submitButton.setAttribute("aria-busy", "true");
			submitButton.textContent = "Sending…";
			status.className = "mohfam-form-status is-pending";
			status.textContent = "Sending your request…";

			try {
				await sessionReady;
				if (!session) {
					session = await refreshSession();
				}
				if (!session) {
					throw new Error("Your form session could not be started. Please refresh the page." + backup);
				}
				formData.append("nonce", session.nonce);
				formData.append("form_token", session.form_token);
				const request = await fetch(mohfamForms.ajaxUrl, {
					method: "POST",
					credentials: "same-origin",
					body: formData,
				});
				const response = await request.json();
				const message =
					response?.data?.message ||
					"We could not send your inquiry. Please try again." + backup;

				if (!request.ok || !response.success) {
					throw new Error(message);
				}

				status.className = "mohfam-form-status is-success";
				status.textContent = message;
				form.reset();
				session = null;
				sessionReady = refreshSession().then((value) => { session = value; });
			} catch (error) {
				status.className = "mohfam-form-status is-error";
				const message = ["TypeError", "SyntaxError"].includes(error.name)
					? "We could not send your inquiry. Please try again."
					: error.message || "We could not send your inquiry. Please try again.";
				status.textContent = message.includes("818") ? message : message + backup;
			} finally {
				if (turnstileWidgetId !== null && window.turnstile) {
					window.turnstile.reset(turnstileWidgetId);
				}

				submitButton.disabled = false;
				submitButton.removeAttribute("aria-busy");
				submitButton.textContent = originalButtonText;
			}
		});
	});
});
