document.addEventListener('DOMContentLoaded', () => {
    const moneyFormatter = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });

    function normalizeMoneyInput(value) {
        let normalized = value.replace(/Rp/gi, '').replace(/\s/g, '');
        if (!normalized) return '';

        if (normalized.includes(',')) {
            normalized = normalized.replace(/\./g, '').replace(',', '.');
        } else if ((normalized.match(/\./g) || []).length > 1 || !/\.\d{1,2}$/.test(normalized)) {
            normalized = normalized.replace(/\./g, '');
        }

        return /^\d+(?:\.\d{1,2})?$/.test(normalized) ? normalized : '';
    }

    document.querySelectorAll('[data-currency-input]').forEach(input => {
        const formatValue = () => {
            const normalized = normalizeMoneyInput(input.value);
            if (!normalized && input.hasAttribute('data-currency-zero-allowed')) {
                input.value = '0';
                return;
            }
            input.value = normalized ? moneyFormatter.format(Number(normalized)) : '';
        };
        input.addEventListener('focus', () => {
            const normalized = normalizeMoneyInput(input.value);
            input.value = normalized === '0' ? '' : normalized;
        });
        input.addEventListener('blur', formatValue);
        formatValue();
    });

    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('[data-currency-input]').forEach(input => {
                const normalized = normalizeMoneyInput(input.value);
                if (normalized) input.value = normalized;
            });
        });
    });

    document.querySelectorAll('[data-clear-zero]').forEach(input => {
        input.addEventListener('focus', () => {
            if (input.value === '0') input.value = '';
        });
        input.addEventListener('blur', () => {
            if (input.value === '') input.value = '0';
        });
    });

    const stockCostPreview = document.querySelector('[data-stock-cost-preview]');
    if (stockCostPreview) {
        const stockQuantity = document.querySelector('[data-stock-quantity]');
        const stockTotalPrice = document.querySelector('[data-stock-total-price]');
        const initialQuantity = Number(stockCostPreview.dataset.initialQuantity);
        const updateStockCostPreview = () => {
            const addedQuantity = Math.max(0, Number(stockQuantity.value) - initialQuantity);
            const totalPrice = Number(normalizeMoneyInput(stockTotalPrice.value)) || 0;
            stockCostPreview.textContent = `Rp ${moneyFormatter.format(addedQuantity > 0 ? totalPrice : 0)}`;
        };
        stockQuantity.addEventListener('input', updateStockCostPreview);
        stockTotalPrice.addEventListener('input', updateStockCostPreview);
        stockTotalPrice.addEventListener('focus', updateStockCostPreview);
        stockTotalPrice.addEventListener('blur', updateStockCostPreview);
        stockQuantity.addEventListener('focus', updateStockCostPreview);
        stockQuantity.addEventListener('blur', updateStockCostPreview);
        updateStockCostPreview();
    }

    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', e => {
            if (!confirm(el.dataset.confirm)) e.preventDefault();
        });
    });

    document.querySelectorAll('[data-auto-submit]').forEach(el => {
        el.addEventListener('change', () => el.closest('form').submit());
    });

    document.querySelectorAll('[data-print-page]').forEach(button => {
        button.addEventListener('click', () => window.print());
    });

    document.querySelectorAll('[data-month-picker]').forEach(form => {
        const valueInput = form.querySelector('[data-month-value]');
        const trigger = form.querySelector('[data-month-trigger]');
        const label = form.querySelector('[data-month-label]');
        const popover = form.querySelector('[data-month-popover]');
        const yearLabel = form.querySelector('[data-month-year]');
        const grid = form.querySelector('[data-month-grid]');
        const previousButton = form.querySelector('[data-month-prev]');
        const nextButton = form.querySelector('[data-month-next]');
        const parsedValue = /^(\d{4})-(\d{2})$/.exec(valueInput.value);
        const selectedYear = parsedValue ? Number(parsedValue[1]) : new Date().getFullYear();
        const selectedMonth = parsedValue ? Number(parsedValue[2]) - 1 : new Date().getMonth();
        let displayedYear = selectedYear;
        const monthFormatter = new Intl.DateTimeFormat('id-ID', { month: 'short' });
        const fullDateFormatter = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' });

        function updateTrigger() {
            label.textContent = fullDateFormatter.format(new Date(selectedYear, selectedMonth, 1));
        }

        function renderMonths() {
            yearLabel.textContent = displayedYear;
            grid.replaceChildren();
            for (let monthIndex = 0; monthIndex < 12; monthIndex++) {
                const monthButton = document.createElement('button');
                monthButton.type = 'button';
                monthButton.className = 'month-picker-option';
                monthButton.textContent = monthFormatter.format(new Date(displayedYear, monthIndex, 1));
                monthButton.setAttribute('aria-pressed', String(displayedYear === selectedYear && monthIndex === selectedMonth));
                if (displayedYear === selectedYear && monthIndex === selectedMonth) {
                    monthButton.classList.add('selected');
                }
                if (displayedYear === new Date().getFullYear() && monthIndex === new Date().getMonth()) {
                    monthButton.classList.add('current');
                }
                monthButton.addEventListener('click', () => {
                    valueInput.value = `${displayedYear}-${String(monthIndex + 1).padStart(2, '0')}`;
                    form.submit();
                });
                grid.append(monthButton);
            }
        }

        function closePicker(restoreFocus = false) {
            popover.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            if (restoreFocus) trigger.focus();
        }

        updateTrigger();
        renderMonths();
        trigger.addEventListener('click', () => {
            const isOpen = trigger.getAttribute('aria-expanded') === 'true';
            popover.hidden = isOpen;
            trigger.setAttribute('aria-expanded', String(!isOpen));
            if (!isOpen) renderMonths();
        });
        previousButton.addEventListener('click', () => {
            displayedYear--;
            renderMonths();
        });
        nextButton.addEventListener('click', () => {
            displayedYear++;
            renderMonths();
        });
        document.addEventListener('click', event => {
            if (!form.contains(event.target)) closePicker();
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !popover.hidden) closePicker(true);
        });
    });

    const installmentDialog = document.querySelector('[data-debt-installment-dialog]');
    if (installmentDialog) {
        const installmentForm = installmentDialog.querySelector('[data-debt-installment-form]');
        const debtIdInput = installmentForm.querySelector('input[name="id"]');
        const amountInput = installmentForm.querySelector('input[name="amount"]');
        const description = installmentDialog.querySelector('[data-debt-installment-description]');
        const remainingLabel = installmentDialog.querySelector('[data-debt-installment-remaining]');
        let activeTrigger = null;
        const currencyFormat = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });

        document.querySelectorAll('[data-debt-installment-trigger]').forEach(trigger => {
            trigger.addEventListener('click', () => {
                activeTrigger = trigger;
                debtIdInput.value = trigger.dataset.debtId;
                amountInput.max = trigger.dataset.debtRemaining;
                amountInput.value = '';
                description.textContent = trigger.dataset.debtDescription;
                remainingLabel.textContent = currencyFormat.format(Number(trigger.dataset.debtRemaining));
                installmentDialog.showModal();
                amountInput.focus();
            });
        });

        installmentDialog.querySelectorAll('[data-debt-installment-close]').forEach(button => {
            button.addEventListener('click', () => installmentDialog.close());
        });
        installmentDialog.addEventListener('click', event => {
            if (event.target === installmentDialog) installmentDialog.close();
        });
        installmentDialog.addEventListener('close', () => {
            if (activeTrigger) activeTrigger.focus();
        });
    }

    const chartPayload = document.querySelector('#finance-chart-data');
    if (chartPayload) {
        const chartData = JSON.parse(chartPayload.textContent);
        const currencyFormat = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
        const chartColors = ['#5793e6', '#eb7f9d', '#59c7a8', '#e9bd67', '#8d7acb', '#64b7cc', '#8492a6'];
        const charts = chartData.isLender ? [
            {
                id: 'income-expense-chart',
                config: {
                    type: 'bar',
                    data: {
                        labels: ['Bulan Ini'],
                        datasets: [
                            { label: 'Dipinjamkan', data: [chartData.lender.monthLoans], backgroundColor: '#5793e6', borderRadius: 4 },
                            { label: 'Pengembalian', data: [chartData.lender.monthRepayments], backgroundColor: '#eb7f9d', borderRadius: 4 }
                        ]
                    },
                    options: { ...chartOptions(currencyFormat), scales: { x: { grid: { display: false }, ticks: { maxRotation: 1, minRotation: 0 } }, y: { beginAtZero: true, ticks: { callback: value => currencyFormat.format(value) } } } }
                }
            },
            {
                id: 'balance-trend-chart',
                config: {
                    type: 'bar',
                    data: {
                        labels: chartData.trend.labels,
                        datasets: [
                            { label: 'Dipinjamkan', data: chartData.lender.trend.loans, backgroundColor: '#5793e6', borderRadius: 4 },
                            { label: 'Pengembalian', data: chartData.lender.trend.repayments, backgroundColor: '#eb7f9d', borderRadius: 4 }
                        ]
                    },
                    options: { ...chartOptions(currencyFormat), scales: { x: { grid: { display: false }, ticks: { maxRotation: 1, minRotation: 0 } }, y: { beginAtZero: true, ticks: { callback: value => currencyFormat.format(value) } } } }
                }
            }
        ] : [
            {
                id: 'income-expense-chart',
                config: {
                    type: 'bar',
                    data: {
                        labels: chartData.trend.labels,
                        datasets: chartData.isPersonal ? [
                            { label: 'Penghasilan', data: chartData.trend.income, backgroundColor: '#5793e6', borderRadius: 4 },
                            { label: 'Pengeluaran', data: chartData.trend.expense, backgroundColor: '#eb7f9d', borderRadius: 4 },
                            { label: 'Tabungan', data: chartData.trend.savings, backgroundColor: '#59c7a8', borderRadius: 4 }
                        ] : [
                            { label: 'Pemasukan', data: chartData.trend.income, backgroundColor: '#5793e6', borderRadius: 4 },
                            { label: 'Pengeluaran', data: chartData.trend.expense, backgroundColor: '#eb7f9d', borderRadius: 4 }
                        ]
                    },
                    options: { ...chartOptions(currencyFormat), scales: { x: { grid: { display: false }, ticks: { maxRotation: 1, minRotation: 0 } }, y: { beginAtZero: true, ticks: { callback: value => currencyFormat.format(value) } } } }
                }
            },
            {
                id: 'balance-trend-chart',
                config: {
                    type: 'line',
                    data: {
                        labels: chartData.trend.labels,
                        datasets: [{ label: 'Saldo', data: chartData.trend.balance, borderColor: '#5793e6', backgroundColor: 'rgba(87,147,230,.12)', fill: true, tension: .35, pointRadius: 3, pointHoverRadius: 5 }]
                    },
                    options: { ...chartOptions(currencyFormat), scales: { x: { grid: { display: false }, ticks: { maxRotation: 1, minRotation: 0 } }, y: { ticks: { callback: value => currencyFormat.format(value) } } } }
                }
            }
        ];

        function chartOptions(formatter) {
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } },
                    tooltip: { callbacks: { label: context => `${context.dataset.label}: ${formatter.format(context.parsed.y ?? context.parsed.x ?? context.parsed)}` } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, minRotation: 0 } },
                    y: { grid: { color: '#edf1f5' } }
                }
            };
        }

        if (window.Chart) {
            charts.forEach(({ id, config }) => {
                const canvas = document.getElementById(id);
                if (canvas) new Chart(canvas, config);
            });

            const expenseCanvas = document.getElementById('expense-category-chart');
            if (expenseCanvas && chartData.isLender && chartData.lender.borrowers.values.length) {
                new Chart(expenseCanvas, {
                    type: 'bar',
                    data: {
                        labels: chartData.lender.borrowers.labels,
                        datasets: [{ label: 'Sisa Pinjaman', data: chartData.lender.borrowers.values, backgroundColor: '#59c7a8', borderRadius: 4 }]
                    },
                    options: {
                        ...chartOptions(currencyFormat),
                        indexAxis: 'y',
                        scales: { x: { beginAtZero: true, ticks: { callback: value => currencyFormat.format(value) } } }
                    }
                });
            } else if (expenseCanvas && !chartData.isLender && chartData.expenseCategories.values.length) {
                new Chart(expenseCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: chartData.expenseCategories.labels,
                        datasets: [{ data: chartData.expenseCategories.values, backgroundColor: chartData.expenseCategories.labels.map((_, index) => chartColors[index % chartColors.length]), borderWidth: 0, hoverOffset: 5 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } }, tooltip: { callbacks: { label: context => `${context.label}: ${currencyFormat.format(context.parsed)}` } } } }
                });
            } else if (expenseCanvas) {
                expenseCanvas.hidden = true;
                const fallback = expenseCanvas.parentElement.querySelector('.chart-fallback');
                fallback.textContent = chartData.isLender ? 'Belum ada pinjaman yang belum dikembalikan.' : 'Belum ada pengeluaran pada bulan ini.';
                fallback.hidden = false;
            }

            const debtCanvas = document.getElementById('business-debt-chart');
            if (debtCanvas && chartData.businessDebts.values.length) {
                new Chart(debtCanvas, {
                    type: 'bar',
                    data: {
                        labels: chartData.businessDebts.labels,
                        datasets: [{ label: 'Sisa Hutang Belum Lunas', data: chartData.businessDebts.values, backgroundColor: '#eb7f9d', borderRadius: 4 }]
                    },
                    options: {
                        ...chartOptions(currencyFormat),
                        indexAxis: 'y',
                        scales: { x: { beginAtZero: true, ticks: { callback: value => currencyFormat.format(value) } } }
                    }
                });
            } else if (debtCanvas) {
                debtCanvas.hidden = true;
                const fallback = debtCanvas.parentElement.querySelector('.chart-fallback');
                fallback.textContent = 'Tidak ada hutang bisnis yang belum lunas pada bulan ini.';
                fallback.hidden = false;
            }

            const receivableCanvas = document.getElementById('business-receivable-chart');
            if (receivableCanvas && chartData.businessReceivables.values.length) {
                new Chart(receivableCanvas, {
                    type: 'bar',
                    data: {
                        labels: chartData.businessReceivables.labels,
                        datasets: [{ label: 'Piutang Belum Dilunasi', data: chartData.businessReceivables.values, backgroundColor: '#5793e6', borderRadius: 4 }]
                    },
                    options: {
                        ...chartOptions(currencyFormat),
                        indexAxis: 'y',
                        scales: { x: { beginAtZero: true, ticks: { callback: value => currencyFormat.format(value) } } }
                    }
                });
            } else if (receivableCanvas) {
                receivableCanvas.hidden = true;
                const fallback = receivableCanvas.parentElement.querySelector('.chart-fallback');
                fallback.textContent = 'Tidak ada piutang bisnis yang belum dilunasi pada bulan ini.';
                fallback.hidden = false;
            }
        } else {
            document.querySelectorAll('.chart-canvas-wrap').forEach(wrap => {
                wrap.querySelector('canvas').hidden = true;
                wrap.querySelector('.chart-fallback').hidden = false;
            });
        }
    }

    const typeInputs = [...document.querySelectorAll('input[name="type"]')];
    const due = document.querySelector('#due_date_wrap');
    const person = document.querySelector('#person_wrap');
    const categoryWrap = document.querySelector('#transaction_category_wrap');
    const transactionCategory = document.querySelector('#transaction_category');
    const categoryGroups = transactionCategory ? [...transactionCategory.querySelectorAll('optgroup')] : [];
    const lenderIncomeSourceWrap = document.querySelector('#lender_income_source_wrap');
    const lenderIncomeSource = document.querySelector('#funding_source');
    const transactionStatus = document.querySelector('#transaction_status_wrap');
    const transactionStatusLabel = document.querySelector('[data-transaction-status]');
    function toggleFields() {
        if (!typeInputs.length) return;
        const selectedType = typeInputs.find(input => input.checked)?.value;
        const showDebtFields = ['debt','receivable'].includes(selectedType);
        const showCategory = ['income','expense'].includes(selectedType);
        if (due) due.style.display = showDebtFields ? 'flex' : 'none';
        if (person) person.style.display = showDebtFields ? 'flex' : 'none';
        if (categoryWrap) categoryWrap.style.display = showCategory ? 'flex' : 'none';
        if (transactionStatus) transactionStatus.style.display = showDebtFields ? 'flex' : 'none';
        if (showDebtFields && transactionStatusLabel) {
            const isExistingPaidDebt = selectedType === transactionStatusLabel.dataset.initialType && transactionStatusLabel.dataset.initialStatus === 'paid';
            transactionStatusLabel.textContent = isExistingPaidDebt ? 'Lunas' : 'Belum Lunas';
            transactionStatusLabel.classList.toggle('status-paid', isExistingPaidDebt);
            transactionStatusLabel.classList.toggle('status-unpaid', !isExistingPaidDebt);
        }
        if (transactionCategory) {
            transactionCategory.required = showCategory;
            transactionCategory.disabled = !showCategory;
        }
        if (transactionCategory && showCategory) {
            categoryGroups.forEach(group => {
                group.hidden = group.dataset.transactionType !== selectedType;
            });
            const selectedOption = transactionCategory.selectedOptions[0];
            if (!selectedOption || selectedOption.parentElement.hidden) {
                const firstOption = transactionCategory.querySelector(`optgroup[data-transaction-type="${selectedType}"] option`);
                if (firstOption) transactionCategory.value = firstOption.value;
            }
        }
        if (lenderIncomeSourceWrap && lenderIncomeSource) {
            const isLenderIncome = selectedType === 'income';
            lenderIncomeSourceWrap.style.display = isLenderIncome ? 'flex' : 'none';
            lenderIncomeSource.required = isLenderIncome;
        }
    }
    if (typeInputs.length) {
        typeInputs.forEach(input => input.addEventListener('change', () => {
            typeInputs.forEach(option => option.closest('.transaction-type-option')?.classList.toggle('selected', option.checked));
            toggleFields();
        }));
        transactionCategory?.addEventListener('change', toggleFields);
        toggleFields();
    }
});
