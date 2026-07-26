document.addEventListener("DOMContentLoaded", () => {
	const forms = document.querySelectorAll(".js-mohfam-form");

	forms.forEach((form) => {
		const status = form.querySelector(".mohfam-form-status");
		const submitButton = form.querySelector('[type="submit"]');
		const originalButtonText = submitButton ? submitButton.textContent : "";
		const turnstileElement = form.querySelector(".mohfam-turnstile");
		let turnstileWidgetId = null;

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
					"Please complete the security verification and try again.";
				return;
			}

			const formData = new FormData(form);
			formData.append("action", "mohfam_submit_form");
			formData.append("nonce", mohfamForms.nonce);
			formData.append("page_url", window.location.href);

			submitButton.disabled = true;
			submitButton.setAttribute("aria-busy", "true");
			submitButton.textContent = "Sending…";
			status.className = "mohfam-form-status is-pending";
			status.textContent = "Sending your request…";

			try {
				const request = await fetch(mohfamForms.ajaxUrl, {
					method: "POST",
					credentials: "same-origin",
					body: formData,
				});
				const response = await request.json();
				const message =
					response?.data?.message ||
					"Something went wrong. Please try again.";

				if (!request.ok || !response.success) {
					throw new Error(message);
				}

				status.className = "mohfam-form-status is-success";
				status.textContent = message;
				form.reset();
			} catch (error) {
				status.className = "mohfam-form-status is-error";
				status.textContent =
					error.message ||
					"Something went wrong. Please try again.";
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
