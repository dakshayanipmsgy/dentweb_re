// Moved from IIFE to global scope
function showToast(message, tone = 'info') {
    const toast = document.createElement('div');
    toast.className = `admin-toast admin-toast--${tone}`;
    toast.innerHTML = `<i class="fa-solid fa-circle-info"></i><span>${message}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.remove();
    }, 5000);
}

function refreshContent() {
    const mainContent = document.querySelector('.admin-records__shell');
    if (!mainContent) return;

    fetch(window.location.href)
        .then(response => response.text())
        .then(html => {
            const newDoc = new DOMParser().parseFromString(html, 'text/html');
            const newContent = newDoc.querySelector('.admin-records__shell');
            if (newContent) {
                mainContent.innerHTML = newContent.innerHTML;
            }
        })
        .catch(error => {
            console.error('Error refreshing content:', error);
            showToast('Failed to refresh content.', 'error');
        });
}

function formatNumber(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '0';
    }
    try {
        return new Intl.NumberFormat('en-IN').format(number);
    } catch (error) {
        return String(number);
    }
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function updateCustomerStateBadges(summary) {
    if (!summary || typeof summary !== 'object') {
        return;
    }

    const normalized = {
        lead: Number(summary.lead ?? summary.leads ?? 0),
        installed: Number(summary.installed ?? 0),
        active: Number(summary.active ?? 0),
        inactive: Number(summary.inactive ?? 0),
        total: Number(summary.total ?? 0),
    };

    document.querySelectorAll('[data-customer-state-count]').forEach((node) => {
        const state = node.getAttribute('data-customer-state-count');
        if (!state || !(state in normalized)) {
            return;
        }
        node.textContent = formatNumber(normalized[state]);
    });

    document.querySelectorAll('[data-dashboard-count]').forEach((node) => {
        const key = node.getAttribute('data-dashboard-count');
        if (!key) {
            return;
        }
        let value;
        switch (key) {
            case 'leads':
                value = normalized.lead;
                break;
            case 'installed':
                value = normalized.installed;
                break;
            case 'customers_total':
            case 'total':
                value = normalized.total;
                break;
            default:
                return;
        }

        if (typeof value === 'number' && Number.isFinite(value)) {
            node.textContent = formatNumber(value);
        }
    });
}

function computeRelativeTimeParts(isoString) {
    const result = { text: '', title: '' };
    if (!isoString || typeof isoString !== 'string') {
        return result;
    }
    const target = new Date(isoString);
    if (Number.isNaN(target.getTime())) {
        return result;
    }

    const now = new Date();
    const diffMs = target.getTime() - now.getTime();
    const diffMinutes = Math.round(diffMs / 60000);
    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

    let value;
    let unit;
    if (Math.abs(diffMinutes) < 60) {
        value = diffMinutes;
        unit = 'minute';
    } else if (Math.abs(diffMinutes) < 60 * 24) {
        value = Math.round(diffMinutes / 60);
        unit = 'hour';
    } else {
        value = Math.round(diffMinutes / (60 * 24));
        unit = 'day';
    }

    return {
        text: formatter.format(value, unit),
        title: target.toLocaleString(),
    };
}


(function () {
  'use strict';

  const storageKey = 'dakshayani-admin-theme';
  const toggleButton = document.querySelector('[data-theme-toggle]');
  const root = document.body;

  function applyTheme(theme, { persist = true } = {}) {
    if (!root) return;
    const value = theme === 'dark' ? 'dark' : 'light';
    root.setAttribute('data-theme', value);
    if (persist && window.localStorage) {
      window.localStorage.setItem(storageKey, value);
    }
  }

  function initTheme() {
    if (!root) return;
    if (window.localStorage) {
      const stored = window.localStorage.getItem(storageKey);
      if (stored === 'dark' || stored === 'light') {
        applyTheme(stored, { persist: false });
        return;
      }
    }
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      applyTheme('dark', { persist: false });
    }
  }

  toggleButton?.addEventListener('click', () => {
    const current = root?.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    applyTheme(current === 'dark' ? 'light' : 'dark');
  });

  function formatRelativeTime(node) {
    if (!node?.dateTime) return;
    const target = new Date(node.dateTime);
    if (Number.isNaN(target.getTime())) return;
    const now = new Date();
    const diffMs = target.getTime() - now.getTime();
    const diffMinutes = Math.round(diffMs / 60000);
    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

    let value;
    let unit;
    if (Math.abs(diffMinutes) < 60) {
      value = diffMinutes;
      unit = 'minute';
    } else if (Math.abs(diffMinutes) < 60 * 24) {
      value = Math.round(diffMinutes / 60);
      unit = 'hour';
    } else {
      value = Math.round(diffMinutes / (60 * 24));
      unit = 'day';
    }

    node.textContent = formatter.format(value, unit);
    node.setAttribute('title', target.toLocaleString());
  }

  initTheme();

  function showModal(title, content, onConfirm) {
    const modal = document.createElement('div');
    modal.className = 'admin-modal';
    modal.innerHTML = `
      <div class="admin-modal__content">
        <h2>${title}</h2>
        <div>${content}</div>
        <div class="admin-modal__actions">
          <button class="btn btn-secondary" id="modal-cancel">Cancel</button>
          <button class="btn btn-primary" id="modal-confirm">Confirm</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    document.getElementById('modal-cancel').addEventListener('click', () => {
      modal.remove();
    });

    document.getElementById('modal-confirm').addEventListener('click', () => {
      onConfirm();
      modal.remove();
    });
  }
  // Expose showModal to global scope for other functions
  window.showModal = showModal;

  const bulkApplyButton = document.getElementById('bulk-apply');
  if (bulkApplyButton) {
    bulkApplyButton.addEventListener('click', () => {
      const actionSelect = document.getElementById('bulk-action');
      const selectedAction = actionSelect.value;
      const selectedOption = actionSelect.options[actionSelect.selectedIndex];
      const selectedCustomerIds = Array.from(document.querySelectorAll('input[name="customer_ids[]"]:checked')).map(cb => cb.value);

      if (selectedCustomerIds.length === 0) {
        showToast('Please select at least one customer.', 'warning');
        return;
      }

      if (!selectedAction) {
        showToast('Please select a bulk action.', 'warning');
        return;
      }

      if (selectedAction === 'export') {
        const state = new URLSearchParams(window.location.search).get('filter') || 'all';
        const url = `customer-export.php?state=${state}&ids=${selectedCustomerIds.join(',')}`;
        window.location.href = url;
        return;
      }

      const onConfirm = () => {
        const payload = {
          bulk_action: selectedAction,
          customer_ids: selectedCustomerIds,
        };

        if (selectedAction === 'change_state') {
          payload.state = selectedOption.dataset.state;
        }

        fetch('api/admin.php?action=bulk-update-customers', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              showToast('Bulk action completed successfully.', 'success');
              refreshContent();
            } else {
              showToast(data.error || 'An error occurred.', 'error');
            }
          })
          .catch(() => {
            showToast('An unexpected error occurred.', 'error');
          });
      };

      showModal(
        'Confirm Bulk Action',
        `<p>Are you sure you want to perform this action on ${selectedCustomerIds.length} customer(s)?</p>`,
        onConfirm
      );
    });
  }

  const csvUploadForm = document.getElementById('csv-upload-form');
  if (csvUploadForm) {
    csvUploadForm.addEventListener('submit', event => {
      event.preventDefault();
      const fileInput = document.getElementById('csv-file');
      const dryRunCheckbox = document.getElementById('csv-dry-run');
      const file = fileInput.files[0];

      if (!file) {
        showToast('Please select a CSV file to upload.', 'warning');
        return;
      }

      const formData = new FormData();
      formData.append('csv_file', file);
      if (dryRunCheckbox.checked) {
        formData.append('dry_run', '1');
      }

      fetch('api/admin.php?action=import-customers', {
        method: 'POST',
        body: formData,
      })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            let message = `CSV import successful. ${data.data.created} created, ${data.data.updated} updated.`;
            if (data.data.skipped > 0) {
                message += ` ${data.data.skipped} skipped.`;
            }
            showToast(message, 'success');
            refreshContent();
          } else {
            showToast(data.error || 'An error occurred during CSV import.', 'error');
          }
        })
        .catch(() => {
          showToast('An unexpected error occurred.', 'error');
        });
    });
  }

  function pollDashboardData() {
    fetch('api/admin.php?action=dashboard-data')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          const { counts } = data.data;
          updateCustomerStateBadges(counts);
        }
      })
      .catch(error => console.error('Error polling dashboard data:', error));
  }

  setInterval(pollDashboardData, 30000);
})();


async function getEmployees() {
  try {
    const response = await fetch('api/admin.php?action=list-employees');
    if (!response.ok) return [];
    const data = await response.json();
    if (data.success && Array.isArray(data.data)) {
      return data.data;
    }
    return [];
  } catch (e) {
    console.error('Failed to fetch employees', e);
    return [];
  }
}

function showFormModal(title, content, onConfirm) {
  const modal = document.createElement('div');
  modal.className = 'admin-modal';
  modal.innerHTML = `
    <div class="admin-modal__content">
      <form id="dynamic-modal-form" style="display: contents;">
        <h2>${title}</h2>
        <div>${content}</div>
        <div class="admin-modal__actions">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Confirm</button>
        </div>
      </form>
    </div>
  `;
  document.body.appendChild(modal);

  const form = modal.querySelector('form');
  const cancelButton = modal.querySelector('[data-dismiss="modal"]');
  const confirmButton = modal.querySelector('button[type="submit"]');

  const handleEscape = (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal();
    }
  };

  const closeModal = () => {
    modal.remove();
    document.removeEventListener('keydown', handleEscape);
  };

  document.addEventListener('keydown', handleEscape);
  cancelButton.addEventListener('click', closeModal);

  let isSubmitting = false;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());

    if (typeof onConfirm !== 'function') {
      closeModal();
      return;
    }

    let result;
    try {
      result = onConfirm(payload);
    } catch (error) {
      console.error('Modal submission failed', error);
      result = false;
    }

    if (result instanceof Promise) {
      isSubmitting = true;
      const originalText = confirmButton.textContent;
      confirmButton.disabled = true;
      confirmButton.textContent = 'Saving...';
      try {
        const outcome = await result;
        if (outcome !== false) {
          closeModal();
        }
      } finally {
        confirmButton.disabled = false;
        confirmButton.textContent = originalText;
        isSubmitting = false;
      }
    } else if (result !== false) {
      closeModal();
    }
  });
}

async function changeState(customerId, state) {
  const performStateChange = async (payload) => {
    try {
      const response = await fetch('api/admin.php?action=change-customer-state', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        showToast('Failed to update customer state.', 'error');
        return false;
      }

      const data = await response.json();
      if (!data.success) {
        showToast(data.error || 'An error occurred.', 'error');
        return false;
      }

      const result = data.data || {};
      const message = result.message || 'Customer state updated.';
      showToast(message, 'success');
      refreshContent();

      const summary = result.summary?.states ?? result.summary ?? null;
      if (summary) {
        updateCustomerStateBadges(summary);
      }

      if (result.activity) {
        appendAdminActivity(result.activity);
      }

      return true;
    } catch (error) {
      console.error('Failed to change customer state', error);
      showToast('An unexpected error occurred.', 'error');
      return false;
    }
  };

  if (state === 'ongoing') {
    const employees = await getEmployees();
    if (!Array.isArray(employees) || employees.length === 0) {
      showToast('Activate at least one employee before moving a lead to ongoing.', 'error');
      return;
    }
    const employeeOptions = employees
      .map((employee) => {
        const id = employee && typeof employee.id !== 'undefined' ? String(employee.id) : '';
        let labelSource = '';
        if (employee && employee.full_name) {
          labelSource = String(employee.full_name);
        } else if (employee && employee.email) {
          labelSource = String(employee.email);
        } else {
          labelSource = `ID ${id}`;
        }
        return `<option value="${id}">${escapeHtml(labelSource)}</option>`;
      })
      .join('');
    const modalContent = `
      <div class="admin-form" style="display: grid; gap: 1rem;">
        <label for="modal-assigned-employee">Assigned Employee</label>
        <select id="modal-assigned-employee" name="assigned_employee_id" required>
          <option value="">-- Select Employee --</option>
          ${employeeOptions}
        </select>
        <label for="modal-system-type">System Type</label>
        <input type="text" id="modal-system-type" name="system_type" required />
        <label for="modal-system-kwp">System kWp</label>
        <input type="number" id="modal-system-kwp" name="system_kwp" step="0.1" required />
      </div>
    `;
    showFormModal('Move to Ongoing', modalContent, (formData) => {
      if (!formData.assigned_employee_id || !formData.system_type || !formData.system_kwp) {
        showToast('All fields are required.', 'warning');
        return false;
      }
      return performStateChange({ ...formData, id: customerId, state });
    });
  } else if (state === 'installed') {
    const today = new Date().toISOString().split('T')[0];
    const modalContent = `
      <div class="admin-form" style="display: grid; gap: 1rem;">
        <label for="modal-handover-date">Handover Date</label>
        <input type="date" id="modal-handover-date" name="handover_date" value="${today}" required />
      </div>
    `;
    showFormModal('Mark as Installed', modalContent, (formData) => {
      if (!formData.handover_date) {
        showToast('Handover date is required.', 'warning');
        return false;
      }
      return performStateChange({ ...formData, id: customerId, state });
    });
  }
}

function deactivateCustomer(customerId) {
    window.showModal(
        'Confirm Deactivation',
        '<p>Are you sure you want to deactivate this customer?</p>',
        () => {
            fetch('api/admin.php?action=deactivate-customer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: customerId }),
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Customer deactivated.', 'success');
                    refreshContent();
                } else {
                    showToast(data.error || 'An error occurred.', 'error');
                }
            })
            .catch(() => {
                showToast('An unexpected error occurred.', 'error');
            });
        }
    );
}

function reactivateCustomer(customerId) {
    window.showModal(
        'Confirm Reactivation',
        '<p>Are you sure you want to reactivate this customer?</p>',
        () => {
            fetch('api/admin.php?action=reactivate-customer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: customerId }),
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Customer reactivated.', 'success');
                    refreshContent();
                } else {
                    showToast(data.error || 'An error occurred.', 'error');
                }
            })
            .catch(() => {
                showToast('An unexpected error occurred.', 'error');
            });
        }
    );
}

function deleteCustomer(customerId) {
    window.showModal(
        'Confirm Deletion',
        '<p>Are you sure you want to permanently delete this customer?</p>',
        () => {
            fetch('api/admin.php?action=delete-customer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: customerId }),
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Customer deleted.', 'success');
                    refreshContent();
                } else {
                    showToast(data.error || 'An error occurred.', 'error');
                }
            })
            .catch(() => {
                showToast('An unexpected error occurred.', 'error');
            });
        }
    );
}
