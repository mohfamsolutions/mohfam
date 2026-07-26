document.addEventListener("DOMContentLoaded", () => {
	const forms = document.querySelectorAll(".js-mohfam-form");

	forms.forEach((form) => {
		const status = form.querySelector(".mohfam-form-status");
		const submitButton = form.querySelector('[type="submit"]');
		const originalButtonText = submitButton ? submitButton.textContent : "";

		form.addEventListener("submit", async (event) => {
			event.preventDefault();

			if (!form.reportValidity() || !submitButton) {
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
				submitButton.disabled = false;
				submitButton.removeAttribute("aria-busy");
				submitButton.textContent = originalButtonText;
			}
		});
	});
});
