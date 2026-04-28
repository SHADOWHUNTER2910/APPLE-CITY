<?php
$c = file_get_contents('index.html');

// Find the broken stub
$pos = strpos($c, 'function loadReceiptHistory(filters = {}');
if ($pos === false) {
    // Try with CRLF
    $pos = strpos($c, "function loadReceiptHistory(filters = {}\r\n");
    echo "pos with CRLF: " . var_export($pos, true) . "\n";
} else {
    echo "Found at pos=$pos\n";
}

// Find next function after it
$nextPos = strpos($c, 'function applyReceiptFilters', $pos ?? 0);
echo "nextPos=$nextPos\n";

if ($pos !== false && $nextPos !== false) {
    $newFn = '    async function loadReceiptHistory(filters = {}) {
      try {
        const params = new URLSearchParams();
        if (filters.search) params.append(\'q\', filters.search);
        if (filters.filterType) params.append(\'filter_type\', filters.filterType);
        if (filters.startDate) params.append(\'start_date\', filters.startDate);
        if (filters.endDate) params.append(\'end_date\', filters.endDate);
        const url = params.toString() ? `api/receipts.php?${params.toString()}` : \'api/receipts.php\';
        const data = await apiCall(url);
        const tbody = document.getElementById(\'tbl-receipt-history\');
        const isAdmin = currentUser && currentUser.role === \'admin\';
        const sectionTitle = document.querySelector(\'#section-receipt-history h2\');
        if (sectionTitle) sectionTitle.innerHTML = isAdmin
          ? \'<i class="bi bi-clock-history"></i> Receipt History (All Users)\'
          : \'<i class="bi bi-clock-history"></i> My Receipt History\';
        if (!data.items || data.items.length === 0) {
          tbody.innerHTML = `<tr><td colspan="${isAdmin?8:7}" class="text-center text-muted">No receipts found</td></tr>`;
          return;
        }
        tbody.innerHTML = data.items.map(receipt => {
          const products = receipt.products || \'N/A\';
          const productsDisplay = products.length > 50 ? products.substring(0, 50) + \'...\' : products;
          const profitMargin = receipt.total > 0 ? ((receipt.total_profit || 0) / receipt.total) * 100 : 0;
          const profitBadgeClass = profitMargin >= 20 ? \'bg-success\' : profitMargin >= 10 ? \'bg-warning\' : \'bg-danger\';
          return `<tr>
            <td>${receipt.invoice_number}</td>
            <td><small class="text-muted" title="${products}">${productsDisplay}</small></td>
            <td>${receipt.customer_name || \'N/A\'}</td>
            <td>${formatCurrency(receipt.total)}</td>
            ${isAdmin ? `<td><span class="badge ${profitBadgeClass}">${formatCurrency(receipt.total_profit || 0)}</span><br><small class="text-muted">${profitMargin.toFixed(1)}%</small></td>` : \'\'}
            <td>${formatDate(receipt.created_at)}</td>
            <td><span class="badge bg-info">${receipt.created_by_username || \'Unknown\'}</span></td>
            <td class="no-print">
              <button class="btn btn-sm btn-info me-1" onclick="viewReceipt(${receipt.id})"><i class="bi bi-eye"></i></button>
              <button class="btn btn-sm btn-primary me-1" onclick="viewAndPrintReceipt(${receipt.id})"><i class="bi bi-printer"></i></button>
              ${isAdmin ? `<button class="btn btn-sm btn-danger" onclick="deleteReceipt(${receipt.id},\'${receipt.invoice_number}\')"><i class="bi bi-trash"></i></button>` : \'\'}
            </td>
          </tr>`;
        }).join(\'\');
      } catch (error) {
        console.error(\'Failed to load receipt history:\', error);
        document.getElementById(\'tbl-receipt-history\').innerHTML = \'<tr><td colspan="8" class="text-center text-danger">Failed to load receipts</td></tr>\';
      }
    }

    ';

    // Replace from broken stub up to (but not including) applyReceiptFilters
    $result = substr($c, 0, $pos) . $newFn . substr($c, $nextPos);
    file_put_contents('index.html', $result);
    echo "Fixed. Size: " . filesize('index.html') . "\n";
} else {
    echo "Could not fix - markers not found\n";
}
