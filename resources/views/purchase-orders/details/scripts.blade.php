<script>
    const detailTabs = [...document.querySelectorAll('.detail-tab')];
    const detailPanels = [...document.querySelectorAll('.detail-panel')];

    function selectDetailTab(tab) {
        detailTabs.forEach(candidate => {
            const selected = candidate === tab;
            candidate.classList.toggle('active', selected);
            candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
            candidate.tabIndex = selected ? 0 : -1;
        });
        detailPanels.forEach(panel => {
            panel.hidden = panel.id !== tab.getAttribute('aria-controls');
        });
    }

    detailTabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selectDetailTab(tab));
        tab.addEventListener('keydown', event => {
            let nextIndex;
            if (event.key === 'ArrowRight') { nextIndex = (index + 1) % detailTabs.length; }
            if (event.key === 'ArrowLeft') { nextIndex = (index + detailTabs.length - 1) % detailTabs.length; }
            if (event.key === 'Home') { nextIndex = 0; }
            if (event.key === 'End') { nextIndex = detailTabs.length - 1; }
            if (nextIndex === undefined) { return; }
            event.preventDefault();
            detailTabs[nextIndex].focus();
            selectDetailTab(detailTabs[nextIndex]);
        });
    });
    document.querySelectorAll('[data-details-tab]').forEach(button => {
        button.addEventListener('click', () => {
            const tab = document.getElementById(button.dataset.detailsTab);
            selectDetailTab(tab);
            tab.focus();
        });
    });
    document.querySelectorAll('a[href="#"]').forEach(link => {
        link.addEventListener('click', event => event.preventDefault());
    });
    document.querySelector('#details-back-top').addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    document.querySelector('#print-order-details').addEventListener('click', () => window.print());

    let detailTabBeforePrint;
    window.addEventListener('beforeprint', () => {
        detailTabBeforePrint = detailTabs.find(tab => tab.getAttribute('aria-selected') === 'true');
        selectDetailTab(document.querySelector('#order-tab'));
    });
    window.addEventListener('afterprint', () => {
        if (detailTabBeforePrint) { selectDetailTab(detailTabBeforePrint); }
    });

    document.querySelectorAll('img.item-image').forEach(image => {
        const showPlaceholder = () => {
            const placeholder = document.createElement('div');
            placeholder.className = 'item-image item-image-placeholder';
            placeholder.setAttribute('role', 'img');
            placeholder.textContent = {{ Illuminate\Support\Js::from(__('purchase_order.document.no_image')) }};
            placeholder.setAttribute('aria-label', placeholder.textContent);
            image.replaceWith(placeholder);
        };
        image.addEventListener('error', showPlaceholder, { once: true });
        if (image.complete && image.naturalWidth === 0) { showPlaceholder(); }
    });
</script>
