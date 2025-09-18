// JavaScript functions for import progress monitoring
// This file contains all the JavaScript functions needed for the import process

function startProgressMonitoring() {
    console.log('Starting progress monitoring...');
    let retryCount = 0;
    const maxRetries = 5;

    const checkProgress = setInterval(function() {
        const ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';

        fetch(ajaxUrl + '?action=fospibay_check_import_progress')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Progress data:', data);
            retryCount = 0;

            if (data.success) {
                updateProgressDisplay(data.data);
                if (data.data.completed) {
                    clearInterval(checkProgress);
                    if (document.getElementById('import-status')) {
                        document.getElementById('import-status').innerHTML = '<span style="color: green;">✓ Importación completada</span>';
                    }
                    showImportSummary(data.data);
                }
            } else {
                console.warn('Progress check returned success: false', data);
            }
        })
        .catch(error => {
            console.error('Error checking progress:', error);
            retryCount++;

            if (retryCount >= maxRetries) {
                clearInterval(checkProgress);
                if (document.getElementById('import-status')) {
                    document.getElementById('import-status').innerHTML = '<span style="color: red;">Error: No se pudo conectar con el servidor</span>';
                }
            }
        });
    }, 2000);

    // Initial immediate check
    setTimeout(() => {
        const ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';
        fetch(ajaxUrl + '?action=fospibay_check_import_progress')
        .then(response => response.json())
        .then(data => {
            console.log('Initial progress check:', data);
            if (data.success) {
                updateProgressDisplay(data.data);
            }
        })
        .catch(error => {
            console.error('Initial check error:', error);
        });
    }, 500);
}

function updateProgressDisplay(data) {
    console.log('Updating display with:', data);

    const progress = data.total_rows > 0 ? ((data.row_index - 2) / (data.total_rows - 1)) * 100 : 0;
    const progressBar = document.getElementById('progress-bar');
    const progressText = document.getElementById('progress-text');

    if (progressBar && progressText) {
        progressBar.style.width = Math.min(100, Math.max(0, progress)) + '%';
        progressText.textContent = Math.round(Math.min(100, Math.max(0, progress))) + '%';
    }

    // Update all stats
    const elements = {
        'rows-processed': Math.max(0, data.row_index - 2),
        'total-rows': Math.max(0, data.total_rows - 1),
        'posts-created': data.imported || 0,
        'posts-updated': data.updated || 0,
        'posts-skipped': data.skipped || 0,
        'images-downloaded': data.images_downloaded || 0,
        'import-status': data.status || 'Procesando...',
        'last-title': data.current_title || '-'
    };

    for (const [id, value] of Object.entries(elements)) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }

    // Add debug messages
    if (data.debug_messages && data.debug_messages.length > 0) {
        const debugDiv = document.getElementById('debug-messages');
        if (debugDiv) {
            data.debug_messages.forEach(msg => {
                const msgElement = document.createElement('div');
                msgElement.innerHTML = msg;
                debugDiv.appendChild(msgElement);
                debugDiv.scrollTop = debugDiv.scrollHeight;
            });
        }
    }
}

function showImportSummary(data) {
    const summaryHtml = `
        <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin-top: 20px; border-radius: 5px;">
            <h3 style="color: #155724; margin-top: 0;">✓ Resumen de Importación Completada</h3>
            <ul style="color: #155724;">
                <li><strong>${data.imported}</strong> entradas creadas</li>
                <li><strong>${data.updated}</strong> entradas actualizadas</li>
                <li><strong>${data.skipped}</strong> entradas omitidas</li>
                <li><strong>${data.images_downloaded || 0}</strong> imágenes descargadas</li>
            </ul>
        </div>
    `;
    const container = document.getElementById('import-progress-container');
    if (container) {
        container.insertAdjacentHTML('beforeend', summaryHtml);
    }
}

function processDirectly() {
    const form = document.querySelector('form');
    if (form) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'process_direct';
        input.value = '1';
        form.appendChild(input);
        form.submit();
    }
}

function processNextBatchDirectly() {
    const ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';

    fetch(ajaxUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=fospibay_process_batch_ajax'
    })
    .then(response => response.json())
    .then(data => {
        console.log('Batch processed:', data);

        // Check progress
        fetch(ajaxUrl + '?action=fospibay_check_import_progress')
        .then(response => response.json())
        .then(progressData => {
            if (progressData.success) {
                updateProgressDisplay(progressData.data);

                if (!progressData.data.completed) {
                    // Process next batch
                    setTimeout(() => { processNextBatchDirectly(); }, 1000);
                } else {
                    if (document.getElementById('import-status')) {
                        document.getElementById('import-status').innerHTML = '<span style="color: green;">✓ Importación completada</span>';
                    }
                    showImportSummary(progressData.data);
                }
            }
        });
    })
    .catch(error => {
        console.error('Error processing batch:', error);
        if (document.getElementById('import-status')) {
            document.getElementById('import-status').innerHTML = '<span style="color: red;">Error al procesar lote</span>';
        }
    });
}

// Make functions globally available
window.startProgressMonitoring = startProgressMonitoring;
window.updateProgressDisplay = updateProgressDisplay;
window.showImportSummary = showImportSummary;
window.processDirectly = processDirectly;
window.processNextBatchDirectly = processNextBatchDirectly;

console.log('Fospibay Import functions loaded');