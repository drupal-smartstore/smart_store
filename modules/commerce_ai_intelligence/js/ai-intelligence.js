(function ($, Drupal, drupalSettings, once) {
    Drupal.behaviors.commerceAiIntelligenceModal = {
        attach(context) {
            const modal = context.getElementById
                ? context.getElementById('detailsModal')
                : document.getElementById('detailsModal');

            if (!modal) {
                return;
            }

            once('commerce-ai-intelligence-open-modal', '#openModalBtn', context).forEach((button) => {
                button.addEventListener('click', () => {
                    modal.style.display = 'flex';
                });
            });

            once('commerce-ai-intelligence-close-modal', '.close-btn', modal).forEach((closeButton) => {
                closeButton.addEventListener('click', () => {
                    modal.style.display = 'none';
                });
            });

            once('commerce-ai-intelligence-backdrop-close', '#detailsModal', context).forEach((modalElement) => {
                modalElement.addEventListener('click', (event) => {
                    if (event.target === modalElement) {
                        modalElement.style.display = 'none';
                    }
                });
            });
        },
    };

    Drupal.behaviors.sideModal = {
        attach: function (context) {
            document.addEventListener('click', function (e) {
                if (e.target.closest('#closeDrawerBtn')) {
                    const dialog = document.querySelector('.ui-dialog-content');

                    if (dialog && typeof jQuery !== 'undefined') {
                        jQuery(dialog).dialog('close');
                    }
                }
            });
        }
    };
})(jQuery, Drupal, drupalSettings, once);