(() => {
	'use strict';
	const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
	document.querySelectorAll('.mohfam-finder').forEach((finder) => {
		const choices = finder.querySelector('.mohfam-finder__choices');
		const radios = [...finder.querySelectorAll('input[name="mohfam-service-choice"]')];
		const panels = [...finder.querySelectorAll('.mohfam-finder__panel')];
		if (!choices || !radios.length || !panels.length) return;
		const showService = (value) => {
			panels.forEach((panel) => { panel.hidden = panel.dataset.service !== value; });
			finder.classList.add('is-enhanced');
		};
		choices.hidden = false;
		radios.forEach((radio) => radio.addEventListener('change', () => showService(radio.value)));
		showService(radios.find((radio) => radio.checked)?.value || radios[0].value);
	});
	const progress = document.querySelector('.mohfam-reading-progress span');
	const topButton = document.querySelector('.mohfam-back-to-top');
	let scheduled = false;
	const updateScroll = () => {
		const distance = document.documentElement.scrollHeight - window.innerHeight;
		if (progress) progress.style.transform = `scaleX(${distance > 0 ? Math.max(0, Math.min(1, window.scrollY / distance)) : 0})`;
		if (topButton) topButton.hidden = window.scrollY < 640;
		scheduled = false;
	};
	const scheduleScroll = () => {
		if (!scheduled) {
			scheduled = true;
			window.requestAnimationFrame(updateScroll);
		}
	};
	window.addEventListener('scroll', scheduleScroll, { passive: true });
	window.addEventListener('resize', scheduleScroll);
	window.addEventListener('load', scheduleScroll);
	if ('ResizeObserver' in window) new ResizeObserver(scheduleScroll).observe(document.body);
	updateScroll();
	topButton?.addEventListener('click', () => {
		document.getElementById('main-content')?.focus({ preventScroll: true });
		window.scrollTo({ top: 0, behavior: motion.matches ? 'instant' : 'smooth' });
	});
	if ('IntersectionObserver' in window && !motion.matches) {
		const targets = [...document.querySelectorAll('.mohfam-division-card, .mohfam-process__step, .mohfam-section-heading--split')];
		const observer = new IntersectionObserver((entries) => {
			entries.forEach((entry) => {
				if (entry.isIntersecting) {
					entry.target.classList.add('is-visible');
					observer.unobserve(entry.target);
				}
			});
		}, { threshold: 0.08 });
		targets.forEach((target) => {
			target.dataset.reveal = '';
			observer.observe(target);
		});
		document.documentElement.classList.add('has-motion');
		document.addEventListener('focusin', (event) => {
			const target = event.target.closest('[data-reveal]');
			if (target) {
				target.classList.add('is-visible');
				observer.unobserve(target);
			}
		});
		motion.addEventListener('change', (event) => {
			if (event.matches) {
				document.documentElement.classList.remove('has-motion');
				observer.disconnect();
			}
		});
	}
})();
