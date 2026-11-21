const secretField = document.getElementById('pga_outbound_shared_secret');
const showSecret = document.getElementById('pga_show_outbound_shared_secret');

if (secretField && showSecret) {
    showSecret.addEventListener('change', () => {
        secretField.type = showSecret.checked ? 'text' : 'password';
    });
}
