(function () {
  'use strict';

  const state = { catalog: null, loading: null };

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));

  const portalConfig = () => window.VTP || {};

  async function loadCatalog() {
    if (state.catalog) return state.catalog;
    if (state.loading) return state.loading;

    const config = portalConfig();
    if (!config.root || !config.nonce) return null;

    state.loading = fetch(`${config.root}catalog`, {
      headers: { 'X-WP-Nonce': config.nonce },
      credentials: 'same-origin',
    })
      .then((response) => {
        if (!response.ok) throw new Error('Unable to load the service catalog.');
        return response.json();
      })
      .then((catalog) => {
        state.catalog = {
          ...catalog,
          categories: (catalog.categories || []).filter((item) => item.active !== false),
          services: (catalog.services || []).filter((item) => item.active !== false),
        };
        return state.catalog;
      })
      .catch(() => null);

    return state.loading;
  }

  const activeCategories = () => (state.catalog?.categories || []).slice().sort((a, b) => (
    Number(a.sortOrder || 0) - Number(b.sortOrder || 0)
  ));

  const servicesForCategory = (categoryId) => (state.catalog?.services || [])
    .filter((service) => Number(service.categoryId) === Number(categoryId))
    .sort((a, b) => Number(a.sortOrder || 0) - Number(b.sortOrder || 0));

  const selectedCurrency = (form) => (
    String(form?.elements?.currency?.value || 'SAR').toUpperCase() === 'USD' ? 'USD' : 'SAR'
  );

  const servicePrice = (service, currency) => Number(
    currency === 'USD'
      ? (service.oneTimePriceUsd ?? service.monthlyPriceUsd ?? 0)
      : (service.oneTimePrice ?? service.monthlyPrice ?? 0)
  );

  function categoryOptions(selected) {
    return [
      '<option value="">Choose category</option>',
      ...activeCategories().map((category) => (
        `<option value="${esc(category.id)}" ${Number(category.id) === Number(selected) ? 'selected' : ''}>${esc(category.name)} (${esc(category.code)})</option>`
      )),
      '<option value="custom">Custom / other</option>',
    ].join('');
  }

  function serviceOptions(categoryId, selected) {
    if (categoryId === 'custom' || !categoryId) {
      return '<option value="custom" selected>Custom service</option>';
    }

    return [
      '<option value="">Choose service</option>',
      ...servicesForCategory(categoryId).map((service) => (
        `<option value="${esc(service.id)}" ${Number(service.id) === Number(selected) ? 'selected' : ''}>${esc(service.name)} · ${esc(service.unit || 'service')}</option>`
      )),
      '<option value="custom">Custom service</option>',
    ].join('');
  }

  function matchingService(description, label) {
    const normalized = String(description || '').trim().toLowerCase();
    const normalizedLabel = String(label || '').trim().toLowerCase();
    return (state.catalog?.services || []).find((service) => (
      String(service.name || '').trim().toLowerCase() === normalized
      || (normalizedLabel && String(service.label || '').trim().toLowerCase() === normalizedLabel && normalized)
    )) || null;
  }

  function rowValues(row) {
    return {
      description: row.querySelector('[name="itemDescription"]')?.value || '',
      label: row.querySelector('[name="itemLabel"]')?.value || '',
      quantity: row.querySelector('[name="itemQuantity"]')?.value || '1',
      price: row.querySelector('[name="itemPrice"]')?.value || '',
      term: row.querySelector('[name="itemTerm"]')?.value || 'once',
      durationMonths: row.querySelector('[name="itemDurationMonths"]')?.value || '0',
      durationDiscount: row.querySelector('[name="itemDurationDiscount"]')?.value || '0',
      annualDiscount: row.querySelector('[name="itemAnnualDiscount"]')?.value || '0',
      packageName: row.querySelector('[name="itemPackageName"]')?.value || '',
    };
  }

  function hiddenInput(name, value) {
    return `<input type="hidden" name="${name}" value="${esc(value)}">`;
  }

  function setCustomState(row, custom) {
    const description = row.querySelector('[data-vtp-description], [name="itemDescription"]');
    const label = row.querySelector('[data-vtp-label]');
    const price = row.querySelector('[data-vtp-price]');
    [description, label, price].forEach((input) => {
      if (input) input.readOnly = !custom;
    });
    row.classList.toggle('vtp-quote-item-custom', custom);
  }

  function updateRowFromSelection(row, form) {
    const categorySelect = row.querySelector('[data-vtp-category]');
    const serviceSelect = row.querySelector('[data-vtp-service]');
    const categoryId = categorySelect?.value || '';
    let serviceId = serviceSelect?.value || '';
    // Choosing a catalog category should produce a usable priced line immediately.
    // The user can still change the service afterward from the same dropdown.
    if (categoryId && categoryId !== 'custom' && !serviceId) {
      const firstService = servicesForCategory(categoryId)[0];
      if (firstService) serviceId = String(firstService.id);
    }
    const category = activeCategories().find((item) => String(item.id) === String(categoryId));
    const service = (state.catalog?.services || []).find((item) => String(item.id) === String(serviceId));
    const custom = categoryId === 'custom' || serviceId === 'custom';

    if (serviceSelect) {
      serviceSelect.innerHTML = serviceOptions(categoryId, serviceId);
      serviceSelect.disabled = !categoryId;
      if (serviceId) serviceSelect.value = serviceId;
    }

    const label = row.querySelector('[data-vtp-label]');
    const description = row.querySelector('[data-vtp-description], [name="itemDescription"]');
    const price = row.querySelector('[data-vtp-price]');
    if (category && label && !custom) label.value = category.code || category.name || '';
    if (service && !custom) {
      if (label) label.value = service.label || category?.code || category?.name || '';
      if (description) description.value = service.name || '';
      if (price) price.value = servicePrice(service, selectedCurrency(form)).toFixed(2);
    }
    setCustomState(row, custom);
  }

  function enhanceRow(row, form, index) {
    if (!row || row.dataset.vtpCatalogRow === '1') return;
    const values = rowValues(row);
    const matched = matchingService(values.description, values.label);
    const categoryId = matched?.categoryId || '';
    const serviceId = matched?.id || '';
    const hidden = [
      hiddenInput('itemDescription', matched?.name || values.description || ''),
      hiddenInput('itemTerm', values.term),
      hiddenInput('itemDurationMonths', values.durationMonths),
      hiddenInput('itemDurationDiscount', values.durationDiscount),
      hiddenInput('itemAnnualDiscount', values.annualDiscount),
      hiddenInput('itemPackageName', values.packageName),
    ].join('');

    row.innerHTML = `${hidden}
      <div class="vtp-quote-cell vtp-quote-category"><label>Category</label><select data-vtp-category aria-label="Service category">${categoryOptions(categoryId)}</select></div>
      <div class="vtp-quote-cell vtp-quote-service"><label>Service</label><select data-vtp-service aria-label="Service" ${categoryId ? '' : 'disabled'}>${serviceOptions(categoryId, serviceId)}</select></div>
      <div class="vtp-quote-cell"><label>Label</label><input name="itemLabel" data-vtp-label value="${esc(matched?.label || values.label || '')}" placeholder="Auto" readonly></div>
      <div class="vtp-quote-cell vtp-quote-quantity"><label>Qty</label><input name="itemQuantity" type="number" min="0.01" step="0.01" value="${esc(values.quantity || '1')}" aria-label="Quantity"></div>
      <div class="vtp-quote-cell vtp-quote-price"><label>Unit price</label><input name="itemPrice" data-vtp-price type="number" min="0" step="0.01" value="${esc(matched ? servicePrice(matched, selectedCurrency(form)).toFixed(2) : values.price)}" placeholder="Auto" aria-label="Unit price" readonly></div>
      ${index ? '<button type="button" class="vtp-drive-remove" data-remove-quote-item aria-label="Remove item">×</button>' : ''}`;

    row.dataset.vtpCatalogRow = '1';
    if (matched) {
      row.querySelector('[data-vtp-category]').value = String(matched.categoryId);
      row.querySelector('[data-vtp-service]').value = String(matched.id);
      setCustomState(row, false);
    } else {
      setCustomState(row, !values.description);
    }
  }

  function enhanceForm(form) {
    if (!form || form.dataset.vtpCatalogReady === '1') return;
    form.dataset.vtpCatalogReady = '1';
    const header = form.querySelector('.vtp-quote-item-head');
    if (header) header.innerHTML = '<span>Category</span><span>Service</span><span>Label</span><span>Qty</span><span>Unit price</span><span></span>';
    form.querySelectorAll('.vtp-quote-item').forEach((row, index) => enhanceRow(row, form, index));
  }

  async function enhanceCurrentForm() {
    const form = document.querySelector('#vtp-quotation-form');
    if (!form) return;
    await loadCatalog();
    enhanceForm(form);
  }

  document.addEventListener('change', (event) => {
    const form = event.target.closest?.('#vtp-quotation-form');
    if (!form) return;
    const row = event.target.closest('.vtp-quote-item');
    if (event.target.matches('[data-vtp-category]')) {
      const service = row?.querySelector('[data-vtp-service]');
      if (service) service.innerHTML = serviceOptions(event.target.value, '');
      updateRowFromSelection(row, form);
    } else if (event.target.matches('[data-vtp-service]')) {
      updateRowFromSelection(row, form);
    } else if (event.target.name === 'currency') {
      form.querySelectorAll('.vtp-quote-item').forEach((itemRow) => {
        const serviceId = itemRow.querySelector('[data-vtp-service]')?.value;
        const service = (state.catalog?.services || []).find((item) => String(item.id) === String(serviceId));
        const price = itemRow.querySelector('[data-vtp-price]');
        if (service && price) price.value = servicePrice(service, selectedCurrency(form)).toFixed(2);
      });
    }
  });

  document.addEventListener('click', async (event) => {
    const add = event.target.closest?.('[data-add-quote-item]');
    if (!add) return;
    const form = add.closest('#vtp-quotation-form');
    const list = form?.querySelector('#vtp-quote-items');
    if (!form || !list) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    await loadCatalog();
    const row = document.createElement('div');
    row.className = 'vtp-quote-item';
    row.innerHTML = '<input type="hidden" name="itemTerm" value="once"><input type="hidden" name="itemDurationMonths" value="0"><input type="hidden" name="itemDurationDiscount" value="0"><input type="hidden" name="itemAnnualDiscount" value="0"><input type="hidden" name="itemPackageName" value="">';
    list.appendChild(row);
    enhanceRow(row, form, list.querySelectorAll('.vtp-quote-item').length - 1);
  }, true);

  const observer = new MutationObserver(() => { enhanceCurrentForm(); });
  observer.observe(document.body, { childList: true, subtree: true });
  enhanceCurrentForm();
})();
