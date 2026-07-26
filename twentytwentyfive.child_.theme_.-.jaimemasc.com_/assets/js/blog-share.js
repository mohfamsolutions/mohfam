document.addEventListener("DOMContentLoaded", () => {
	const share = document.querySelector(".js-mohfam-share");

	if (!share) {
		return;
	}

	const canonical = document.querySelector('link[rel="canonical"]');
	const heading = document.querySelector("main h1");
	const description = document.querySelector('meta[name="description"]');
	const pageUrl = canonical?.href || window.location.href;
	const pageTitle = heading?.textContent.trim() || document.title;
	const pageDescription = description?.content.trim() || "";
	const status = share.querySelector(".mohfam-share__status");
	const nativeButton = share.querySelector('[data-share-action="native"]');
	const copyButton = share.querySelector('[data-share-action="copy"]');
	const facebook = share.querySelector('[data-share-service="facebook"]');
	const linkedin = share.querySelector('[data-share-service="linkedin"]');
	const email = share.querySelector('[data-share-service="email"]');

	if (facebook) {
		facebook.href =
			`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(pageUrl)}`;
	}

	if (linkedin) {
		linkedin.href =
			`https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(pageUrl)}`;
	}

	if (email) {
		const emailBody = pageDescription
			? `${pageDescription}\n\n${pageUrl}`
			: pageUrl;
		email.href =
			`mailto:?subject=${encodeURIComponent(pageTitle)}&body=${encodeURIComponent(emailBody)}`;
	}

	if (nativeButton && navigator.share) {
		nativeButton.hidden = false;
		nativeButton.addEventListener("click", async () => {
			try {
				await navigator.share({
					title: pageTitle,
					text: pageDescription,
					url: pageUrl,
				});
			} catch (error) {
				if (error.name !== "AbortError") {
					status.textContent =
						"Sharing is unavailable. You can copy the link instead.";
				}
			}
		});
	}

	if (copyButton) {
		copyButton.addEventListener("click", async () => {
			try {
				await copyText(pageUrl);
				status.textContent = "Link copied to your clipboard.";
			} catch {
				status.textContent =
					"Could not copy the link. Please copy it from your browser.";
			}
		});
	}
});

async function copyText(text) {
	if (navigator.clipboard && window.isSecureContext) {
		await navigator.clipboard.writeText(text);
		return;
	}

	const input = document.createElement("textarea");
	input.value = text;
	input.setAttribute("readonly", "");
	input.style.position = "fixed";
	input.style.opacity = "0";
	document.body.appendChild(input);
	input.select();

	const copied = document.execCommand("copy");
	input.remove();

	if (!copied) {
		throw new Error("Copy command failed.");
	}
}
