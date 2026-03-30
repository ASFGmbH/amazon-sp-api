(function ($) {
    'use strict';

    function getToastInstance() {
        const toastEl = document.getElementById('liveToast');
        if (!toastEl) {
            return null;
        }

        return bootstrap.Toast.getOrCreateInstance(toastEl, {
            delay: 8000
        });
    }

    function showToast(message) {
        const toastBody = document.getElementById('toastMessage');
        const toast = getToastInstance();

        if (!toastBody || !toast) {
            alert(message);
            return;
        }

        toastBody.textContent = message;
        toast.show();
    }

    function initLazyImages() {
        const lazyImages = document.querySelectorAll('img.lazy[data-src]');

        if (!lazyImages.length) {
            return;
        }

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(function (entries, obs) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }

                    const img = entry.target;
                    const src = img.getAttribute('data-src');

                    if (src) {
                        img.setAttribute('src', src);
                        img.removeAttribute('data-src');
                        img.classList.remove('lazy');
                    }

                    obs.unobserve(img);
                });
            }, {
                rootMargin: '100px 0px'
            });

            lazyImages.forEach(function (img) {
                observer.observe(img);
            });

            return;
        }

        lazyImages.forEach(function (img) {
            const src = img.getAttribute('data-src');
            if (src) {
                img.setAttribute('src', src);
                img.removeAttribute('data-src');
                img.classList.remove('lazy');
            }
        });
    }

    function buildSuccessMessage(response, model) {
        const parts = [];

        if (response && response.message) {
            parts.push(String(response.message));
        } else {
            parts.push('Upload abgeschlossen.');
        }

        if (response && response.model) {
            parts.push('Modell: ' + String(response.model));
        } else if (model) {
            parts.push('Modell: ' + String(model));
        }

        if (response && response.parent_sku) {
            parts.push('Parent: ' + String(response.parent_sku));
        }

        if (response && typeof response.child_count !== 'undefined') {
            parts.push('Children: ' + String(response.child_count));
        }

        return parts.join(' | ');
    }

    function buildErrorMessage(xhr, fallbackMessage) {
        let message = fallbackMessage || 'Unbekannter Fehler beim Upload.';

        if (xhr && xhr.responseJSON) {
            if (xhr.responseJSON.message) {
                message = String(xhr.responseJSON.message);
            } else if (xhr.responseJSON.error) {
                message = String(xhr.responseJSON.error);
            }
        } else if (xhr && xhr.responseText) {
            try {
                const parsed = JSON.parse(xhr.responseText);
                if (parsed.message) {
                    message = String(parsed.message);
                } else {
                    message = xhr.responseText;
                }
            } catch (e) {
                message = xhr.responseText;
            }
        }

        return message;
    }

    function initPushButtons() {
        $(document).on('click', '.push-btn', function () {
            const $btn = $(this);

            if ($btn.prop('disabled')) {
                return;
            }

            const model = String($btn.data('model') || '').trim();
            if (model === '') {
                showToast('Kein Modell gefunden.');
                return;
            }

            const originalText = $btn.text();
            const dryRun = 0;

            $btn.prop('disabled', true).text('Sende an Amazon ...');

            $.ajax({
                url: 'push.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    model: model,
                    dry_run: dryRun
                }
            })
                .done(function (response) {
                    window.lastAmazonPushResponse = response;

                    console.log('Amazon push response:', response);
                    console.log('Amazon push results:', JSON.stringify(response.results || [], null, 2));

                    if (response && response.success) {
                        showToast(buildSuccessMessage(response, model));
                        $btn
                            .removeClass('btn-primary btn-secondary btn-danger')
                            .addClass('btn-success')
                            .text('Upload gesendet');
                        return;
                    }

                    const message = response && response.message
                        ? String(response.message)
                        : 'Der Upload wurde nicht erfolgreich abgeschlossen.';

                    showToast(message);

                    $btn
                        .removeClass('btn-primary btn-success')
                        .addClass('btn-danger')
                        .text('Fehler beim Upload');
                })
                .fail(function (xhr) {
                    console.error('Amazon push failed:', xhr);

                    const message = buildErrorMessage(xhr, 'Fehler beim Senden an Amazon.');
                    showToast(message);

                    $btn
                        .removeClass('btn-primary btn-success')
                        .addClass('btn-danger')
                        .text('Fehler beim Upload');
                })
                .always(function () {
                    window.setTimeout(function () {
                        $btn.prop('disabled', false);

                        if ($btn.hasClass('btn-danger')) {
                            $btn.text('Erneut versuchen');
                        } else if ($btn.hasClass('btn-success')) {
                            $btn.text('Upload gesendet');
                        } else {
                            $btn.text(originalText);
                        }
                    }, 1200);
                });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initLazyImages();
        initPushButtons();
    });

})(jQuery);
