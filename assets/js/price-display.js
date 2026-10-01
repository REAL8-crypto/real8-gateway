(function(){
            var real8Price = Number(real8_prices.rate);

            function formatReal8(amount) {
                if (amount >= 1000000) return (amount / 1000000).toFixed(2) + 'M';
                if (amount >= 1000) return amount.toLocaleString('en-US', {maximumFractionDigits: 0});
                if (amount >= 1) return amount.toFixed(2);
                return amount.toFixed(4);
            }

            function appendReal8(el) {
                if (!el || el.querySelector('.real8-equivalent')) return;
                var text = el.textContent.replace(/[^0-9.,]/g, '').replace(',', '');
                var usd = parseFloat(text);
                if (!usd || usd <= 0) return;
                var r8 = usd / real8Price;
                var span = document.createElement('span');
                span.className = 'real8-equivalent';
                span.innerHTML = '&asymp; ' + formatReal8(r8) + ' $REAL8';
                el.parentNode.insertBefore(span, el.nextSibling);
            }

            function update() {
                // Recurring total header amount
                document.querySelectorAll('.recurring-total-header > span:last-child').forEach(appendReal8);
                // Recurring details subtotal
                document.querySelectorAll('.recurring-totals-table .total td').forEach(appendReal8);
            }

            jQuery(document.body).on('updated_checkout', update);
            update();
        })();
