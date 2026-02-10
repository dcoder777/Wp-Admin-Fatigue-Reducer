(function () {
  function identifyNotice(noticeEl) {
    const text = (noticeEl.textContent || '').trim().replace(/\s+/g, ' ').toLowerCase();
    const classes = noticeEl.className || '';
    const sourceMatch = classes.match(/\b([a-z0-9_-]*(plugin|woocommerce|yoast|elementor)[a-z0-9_-]*)\b/i);
    const source = sourceMatch ? sourceMatch[1].toLowerCase() : 'unknown';
    const type = classes.includes('notice-error') || classes.includes('error') ? 'error' : classes.includes('notice-warning') ? 'warning' : classes.includes('notice-success') ? 'success' : 'info';
    const raw = `${source}|${type}|${text}`;

    let hash = 0;
    for (let i = 0; i < raw.length; i += 1) {
      hash = ((hash << 5) - hash) + raw.charCodeAt(i);
      hash |= 0;
    }
    return String(hash);
  }

  function sendAnalytics(action, noticeId) {
    if (!window.AFRData || !window.wp || !window.wp.apiFetch) {
      return;
    }

    window.wp.apiFetch({
      path: '/afr/v1/analytics',
      method: 'POST',
      headers: {
        'X-WP-Nonce': window.AFRData.nonce
      },
      data: {
        action,
        notice_id: noticeId
      }
    }).catch(() => {
      // Silent fallback to keep admin UX unchanged.
    });
  }

  function setupNoticeTracking() {
    const notices = document.querySelectorAll('.notice');
    notices.forEach((noticeEl) => {
      const id = noticeEl.getAttribute('data-afr-notice-id') || identifyNotice(noticeEl);
      noticeEl.setAttribute('data-afr-notice-id', id);

      const dismiss = noticeEl.querySelector('.notice-dismiss');
      if (dismiss) {
        dismiss.addEventListener('click', () => sendAnalytics('dismiss', id));
      }

      noticeEl.querySelectorAll('a,button,input[type="submit"]').forEach((interactiveEl) => {
        interactiveEl.addEventListener('click', () => sendAnalytics('interact', id));
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupNoticeTracking);
  } else {
    setupNoticeTracking();
  }
})();
