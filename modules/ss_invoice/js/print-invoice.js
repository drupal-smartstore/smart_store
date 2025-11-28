(function ($, Drupal, once) {
    Drupal.behaviors.globalPrintButton = {
        attach: function (context, settings) {
            $(once('printButtonGlobal', '.print-button', context)).on('click', function (e) {
                e.preventDefault();

                var wrapper = document.getElementById('print-slip-wrapper');
                if (!wrapper) {
                    console.error("print-slip-wrapper not found!");
                    return;
                }

                var printContents = wrapper.innerHTML;
                var printWindow = window.open('', '', 'height=900,width=900');
                var doc = printWindow.document;

                doc.open();

                doc.write('<html><head><title>Print</title>');
                document.querySelectorAll('link[rel="stylesheet"]').forEach(function (link) {
                    doc.write('<link rel="stylesheet" href="' + link.href + '" type="text/css" />');
                });

                document.querySelectorAll('style').forEach(function (styleTag) {
                    doc.write('<style>' + styleTag.innerHTML + '</style>');
                });

                doc.write('</head><body>');
                doc.write(printContents);
                doc.write('</body></html>');
                doc.close();

                // Ensure CSS loads before triggering print
                setTimeout(function () {
                    printWindow.focus();
                    printWindow.print();
                    printWindow.close();
                }, 500);
            });
        }
    };
})(jQuery, Drupal, once);
