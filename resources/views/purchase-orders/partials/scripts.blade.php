<script>
    document.querySelectorAll('a[href="#"]').forEach(link => link.addEventListener('click', event => event.preventDefault()));
    document.querySelector('.notice .close').addEventListener('click', () => {
        document.querySelector('#notice').style.visibility = 'hidden';
    });
    document.querySelector('.switch').addEventListener('click', event => {
        const button = event.currentTarget;
        button.setAttribute('aria-checked', button.getAttribute('aria-checked') === 'false' ? 'true' : 'false');
    });
    document.querySelectorAll('.select-all,.order-checkbox').forEach(box => box.addEventListener('change', () => {
        document.querySelectorAll('.select-all,.order-checkbox').forEach(other => { other.checked = box.checked; });
    }));
    document.querySelector('#back-top').addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    document.querySelector('#print-order').addEventListener('click', () => window.print());
    document.querySelectorAll('img.product-image').forEach(image => {
        const showPlaceholder = () => {
            const placeholder = document.createElement('div');
            placeholder.className = 'product-image product-image-placeholder';
            placeholder.setAttribute('role', 'img');
            placeholder.textContent = {{ Illuminate\Support\Js::from(__('purchase_order.document.no_image')) }};
            placeholder.setAttribute('aria-label', placeholder.textContent);
            image.replaceWith(placeholder);
        };
        image.addEventListener('error', showPlaceholder, { once: true });
        if (image.complete && image.naturalWidth === 0) {
            showPlaceholder();
        }
    });

    const foldProducts = document.querySelector('.fold-products');
    foldProducts?.addEventListener('click', () => {
        const expanded = foldProducts.getAttribute('aria-expanded') === 'true';
        document.querySelector('#products').hidden = expanded;
        foldProducts.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        foldProducts.querySelector('span').textContent = expanded
            ? {{ Illuminate\Support\Js::from(__('purchase_order.document.show_products')) }}
            : {{ Illuminate\Support\Js::from(__('purchase_order.document.hide_products')) }};
        foldProducts.querySelector('.chevron').classList.toggle('up', !expanded);
    });

    const search = document.querySelector('#order-search');
    const order = document.querySelector('#order');
    const orderNumber = document.querySelector('#saved-order-number').textContent.trim();
    const productNames = [...document.querySelectorAll('.product-title')].map(element => element.textContent);
    search.addEventListener('submit', event => {
        event.preventDefault();
        const number = search.elements.orderNo.value.trim();
        const keyword = search.elements.keyword.value.trim().toLocaleLowerCase();
        const visible = (!number || orderNumber.includes(number)) && (!keyword || orderNumber.includes(keyword) || productNames.some(name => name.toLocaleLowerCase().includes(keyword)));
        order.hidden = !visible;
        document.querySelector('#empty-state').style.display = visible ? 'none' : 'block';
        document.querySelector('.result-number').textContent = visible ? '1' : '0';
    });
    document.querySelector('#reset-filter').addEventListener('click', () => search.reset());
    search.addEventListener('reset', () => {
        order.hidden = false;
        document.querySelector('#empty-state').style.display = 'none';
        document.querySelector('.result-number').textContent = '1';
    });
</script>
