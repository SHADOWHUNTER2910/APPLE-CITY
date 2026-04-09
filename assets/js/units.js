// Product Units Management Functions

// Global variable to store current product's units
let currentProductUnits = [];

// Manage Units Modal
async function manageUnits(productId, productName) {
  document.getElementById('units-product-id').value = productId;
  document.getElementById('units-product-name').textContent = productName;
  
  // Load units for this product
  await loadProductUnits(productId);
  
  // Show modal
  const modal = new bootstrap.Modal(document.getElementById('manageUnitsModal'));
  modal.show();
}

// Load units for a product
async function loadProductUnits(productId) {
  try {
    const data = await apiCall(`api/product-units.php?product_id=${productId}`);
    currentProductUnits = data.items || [];
    
    const tbody = document.getElementById('tbl-units');
    
    if (currentProductUnits.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No units defined yet</td></tr>';
      return;
    }
    
    tbody.innerHTML = currentProductUnits.map(unit => `
      <tr>
        <td>${unit.unit_name}</td>
        <td>${unit.unit_abbreviation}</td>
        <td>${unit.conversion_factor}x</td>
        <td>${formatCurrency(unit.unit_price)}</td>
        <td>
          ${unit.is_base_unit ? 
            '<span class="badge bg-primary">Base</span>' : 
            `<button class="btn btn-sm btn-outline-primary" onclick="setBaseUnit(${unit.id})" title="Set as base unit">Set Base</button>`
          }
        </td>
        <td>
          <button class="btn btn-sm btn-outline-warning me-1" onclick="editUnit(${unit.id})" title="Edit">
            <i class="bi bi-pencil"></i>
          </button>
          ${!unit.is_base_unit || currentProductUnits.length === 1 ? `
            <button class="btn btn-sm btn-outline-danger" onclick="deleteUnit(${unit.id})" title="Delete">
              <i class="bi bi-trash"></i>
            </button>
          ` : ''}
        </td>
      </tr>
    `).join('');
  } catch (error) {
    console.error('Failed to load units:', error);
    alert('Failed to load units: ' + error.message);
  }
}

// Add unit form submission
document.addEventListener('DOMContentLoaded', function() {
  const addUnitForm = document.getElementById('form-add-unit');
  if (addUnitForm) {
    addUnitForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      
      const productId = document.getElementById('units-product-id').value;
      const formData = new FormData(this);
      const unitData = Object.fromEntries(formData);
      unitData.product_id = parseInt(productId);
      unitData.conversion_factor = parseFloat(unitData.conversion_factor);
      unitData.unit_price = parseFloat(unitData.unit_price);
      
      try {
        await apiCall('api/product-units.php', {
          method: 'POST',
          body: JSON.stringify(unitData)
        });
        
        this.reset();
        await loadProductUnits(productId);
        alert('Unit added successfully!');
      } catch (error) {
        alert('Failed to add unit: ' + error.message);
      }
    });
  }
});

// Set unit as base unit
async function setBaseUnit(unitId) {
  if (!confirm('Set this unit as the base unit? This will affect stock tracking.')) {
    return;
  }
  
  try {
    await apiCall(`api/product-units.php?id=${unitId}`, {
      method: 'PUT',
      body: JSON.stringify({ is_base_unit: 1 })
    });
    
    const productId = document.getElementById('units-product-id').value;
    await loadProductUnits(productId);
    alert('Base unit updated successfully!');
  } catch (error) {
    alert('Failed to update base unit: ' + error.message);
  }
}

// Delete unit
async function deleteUnit(unitId) {
  if (!confirm('Are you sure you want to delete this unit?')) {
    return;
  }
  
  try {
    await apiCall(`api/product-units.php?id=${unitId}`, {
      method: 'DELETE'
    });
    
    const productId = document.getElementById('units-product-id').value;
    await loadProductUnits(productId);
    alert('Unit deleted successfully!');
  } catch (error) {
    alert('Failed to delete unit: ' + error.message);
  }
}

// Edit unit (inline editing)
async function editUnit(unitId) {
  const unit = currentProductUnits.find(u => u.id === unitId);
  if (!unit) return;
  
  const newName = prompt('Unit Name:', unit.unit_name);
  if (!newName) return;
  
  const newAbbr = prompt('Abbreviation:', unit.unit_abbreviation);
  if (!newAbbr) return;
  
  const newConversion = prompt('Conversion Factor:', unit.conversion_factor);
  if (!newConversion) return;
  
  const newPrice = prompt('Unit Price:', unit.unit_price);
  if (!newPrice) return;
  
  try {
    await apiCall(`api/product-units.php?id=${unitId}`, {
      method: 'PUT',
      body: JSON.stringify({
        unit_name: newName,
        unit_abbreviation: newAbbr,
        conversion_factor: parseFloat(newConversion),
        unit_price: parseFloat(newPrice)
      })
    });
    
    const productId = document.getElementById('units-product-id').value;
    await loadProductUnits(productId);
    alert('Unit updated successfully!');
  } catch (error) {
    alert('Failed to update unit: ' + error.message);
  }
}

// Update product selection to load units
function setupProductUnitSelection() {
  const productSelect = document.getElementById('select-product');
  const unitSelect = document.getElementById('select-unit');
  const priceInput = document.getElementById('input-unit-price');
  
  if (!productSelect || !unitSelect) return;
  
  productSelect.addEventListener('change', async function() {
    const productId = parseInt(this.value);
    
    if (!productId) {
      unitSelect.disabled = true;
      unitSelect.innerHTML = '<option value="">Select unit</option>';
      priceInput.value = '';
      return;
    }
    
    // Load units for selected product
    try {
      const data = await apiCall(`api/product-units.php?product_id=${productId}`);
      const units = data.items || [];
      
      if (units.length === 0) {
        unitSelect.disabled = true;
        unitSelect.innerHTML = '<option value="">No units available</option>';
        return;
      }
      
      unitSelect.disabled = false;
      unitSelect.innerHTML = '<option value="">Select unit</option>' +
        units.map(unit => `
          <option value="${unit.id}" data-price="${unit.unit_price}" data-conversion="${unit.conversion_factor}">
            ${unit.unit_name} (${unit.unit_abbreviation}) - ${formatCurrency(unit.unit_price)}
          </option>
        `).join('');
      
      // Auto-select base unit
      const baseUnit = units.find(u => u.is_base_unit);
      if (baseUnit) {
        unitSelect.value = baseUnit.id;
        priceInput.value = baseUnit.unit_price;
      }
    } catch (error) {
      console.error('Failed to load units:', error);
      unitSelect.disabled = true;
      unitSelect.innerHTML = '<option value="">Error loading units</option>';
    }
  });
  
  unitSelect.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const price = selectedOption.dataset.price;
    priceInput.value = price || '';
  });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', setupProductUnitSelection);
