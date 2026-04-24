jQuery(function($) {
    const config = window.TIMUExtLinkData || {};
    const ajaxUrl = config.ajaxUrl || window.ajaxurl;
    const nonce = config.nonce || '';
    const testAction = config.testAction || '';
    const urls = Array.isArray(config.urls) ? config.urls.slice() : [];
    const strings = config.strings || {};
    const batchSize = Math.max(1, parseInt(config.batchSize, 10) || 20);

    let isCancelled = false;
    let testedCount = 0;

    const updateProgress = (total) => {
        const pct = total > 0 ? Math.round((testedCount / total) * 100) : 100;
        $('#timu-elc-progress-bar').css('width', pct + '%');
        $('#timu-elc-progress-text').text(pct + '%');
    };

    const appendReport = (text) => {
        const $report = $('#timu-elc-report');
        const current = $report.html();
        $report.html(current + '<div style="padding:4px 0;">' + $('<div/>').text(text).html() + '</div>');
        $report.show();
    };

    const hash = (str) => {
        let h = 0;
        for (let i = 0; i < str.length; i += 1) {
            h = ((h << 5) - h) + str.charCodeAt(i);
            h |= 0;
        }
        return String(Math.abs(h));
    };

    const updateStatusForUrl = (url, statusText, cssColor) => {
        const urlHash = hash(url);
        $('.timu-elc-status').each(function() {
            const $el = $(this);
            const rowUrl = $el.closest('tr').data('url');
            if (rowUrl === url) {
                $el.text(statusText).css({ color: cssColor, fontWeight: 600 });
            }
            if ($el.data('url-hash') === urlHash) {
                $el.text(statusText).css({ color: cssColor, fontWeight: 600 });
            }
        });
    };

    $('#timu-elc-view-grouped').on('click', function() {
        $('#timu-elc-grouped-view').show();
        $('#timu-elc-list-view').hide();
    });

    $('#timu-elc-view-list').on('click', function() {
        $('#timu-elc-grouped-view').hide();
        $('#timu-elc-list-view').show();
    });

    $('#timu-elc-test-links').on('click', function() {
        const total = urls.length;
        if (!total) {
            appendReport('No external links found to validate.');
            return;
        }

        const queue = urls.slice();
        isCancelled = false;
        testedCount = 0;

        $('#timu-elc-report').hide().empty();
        $('#timu-elc-progress-container').show();
        $('#timu-elc-cancel-tests').show();
        $('#timu-elc-test-links').prop('disabled', true).text(strings.testing || 'Testing links...');

        const runNextBatch = () => {
            if (isCancelled) {
                appendReport(strings.cancelled || 'Validation cancelled.');
                $('#timu-elc-cancel-tests').hide();
                $('#timu-elc-test-links').prop('disabled', false).text('Validate ' + total + ' External Links');
                return;
            }

            if (!queue.length) {
                updateProgress(total);
                appendReport(strings.completed || 'Validation complete.');
                $('#timu-elc-cancel-tests').hide();
                $('#timu-elc-test-links').prop('disabled', false).text('Validate ' + total + ' External Links');
                return;
            }

            const batch = queue.splice(0, batchSize);
            $.post(ajaxUrl, {
                action: testAction,
                nonce: nonce,
                urls: batch
            }).done(function(res) {
                if (!res || !res.success || !res.data) {
                    appendReport('Link test batch failed.');
                    isCancelled = true;
                    return;
                }

                const results = Array.isArray(res.data.results) ? res.data.results : [];
                results.forEach(function(result) {
                    testedCount += 1;
                    const code = parseInt(result.statusCode, 10) || 0;
                    const label = code > 0 ? (result.status + ' (' + code + ')') : (result.status + ' (n/a)');
                    const color = result.valid ? '#067c1b' : '#b42318';
                    updateStatusForUrl(result.url, label, color);
                    appendReport(result.url + ' -> ' + label + ' ' + (result.message || ''));
                });

                updateProgress(total);
            }).fail(function() {
                appendReport('Link test request failed.');
                isCancelled = true;
            }).always(runNextBatch);
        };

        runNextBatch();
    });

    $('#timu-elc-cancel-tests').on('click', function() {
        isCancelled = true;
    });
});
