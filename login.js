(function () {
  const form = document.getElementById('login-form');
  if (!form) return;

  const hintEl = form.querySelector('[data-role-hint]');
  const identifierLabel = form.querySelector('[data-identifier-label]');
  const identifierInput = form.querySelector('[data-identifier-input]');
  const roleButtons = form.querySelectorAll('.role-button');
  const roleInputs = form.querySelectorAll('input[name="login_type"]');

  const applyRole = (role) => {
    const isCustomer = role === 'customer';

    if (identifierInput && identifierLabel) {
      identifierLabel.textContent = isCustomer ? 'Mobile number' : 'Email ID';
      identifierInput.type = isCustomer ? 'tel' : 'email';
      identifierInput.placeholder = isCustomer ? 'Enter 10-digit mobile' : 'you@example.com';
      identifierInput.setAttribute('inputmode', isCustomer ? 'numeric' : 'email');
      identifierInput.setAttribute('autocomplete', isCustomer ? 'tel' : 'email');
    }

    if (hintEl) {
      hintEl.textContent = isCustomer
        ? 'Customers should sign in with their registered mobile number and password.'
        : 'Administrators must use the credentials issued by Dakshayani Enterprises.';
    }

    roleButtons.forEach((button) => {
      const buttonRole = button.getAttribute('data-role');
      if (!buttonRole) return;
      button.classList.toggle('is-active', buttonRole === role);
    });
  };

  const selected = Array.from(roleInputs).find((input) => input instanceof HTMLInputElement && input.checked);
  applyRole(selected ? selected.value : 'admin');

  roleInputs.forEach((input) => {
    input.addEventListener('change', () => applyRole(input.value));
  });
})();
