(function ($, Drupal, once) {
    Drupal.behaviors.invoicePrint = {
        attach: function (context, settings) {
            $(once('printButton', '.print-button', context)).on('click', function (e) {
                e.preventDefault();

                // Target the div you want to print
                var printContents = document.getElementById('print-slip-wrapper').innerHTML;

                // Open a new window for printing
                var printWindow = window.open('', '', 'height=600,width=800');
                var doc = printWindow.document;
                doc.open();
                var html = doc.createElement('html');
                var head = doc.createElement('head');
                var title = doc.createElement('title');
              //  title.textContent = 'Invoice';
                head.appendChild(title);
                var body = doc.createElement('body');
                body.innerHTML = printContents;
                html.appendChild(head);
                html.appendChild(body);
                doc.appendChild(html);
                doc.close();
                printWindow.focus();

                // Trigger print
                printWindow.print();
                printWindow.close();
            });
        }
    };
})(jQuery, Drupal, once);