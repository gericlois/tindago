<?php if (!empty($admin_shell_open)): ?>
    </div>
  </div>
</div>
<?php endif; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/main.js') ?>"></script>

  <!-- Shared document/image/proof viewer — any link with
       data-bs-toggle="modal" data-bs-target="#docViewerModal" plus
       data-doc-url (and optionally data-doc-title) opens its file here
       instead of a new tab. One modal reused by every such link on the
       page; the iframe src is only set while the modal is open so a large
       PDF isn't left loaded in the background. -->
  <div class="modal fade" id="docViewerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="docViewerModalTitle">Document</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-0" style="height:80vh;">
          <iframe id="docViewerModalFrame" src="" style="width:100%;height:100%;border:0;"></iframe>
        </div>
      </div>
    </div>
  </div>
  <script>
    (function () {
      var modalEl = document.getElementById('docViewerModal');
      if (!modalEl) return;
      var frame = document.getElementById('docViewerModalFrame');
      var title = document.getElementById('docViewerModalTitle');
      modalEl.addEventListener('show.bs.modal', function (e) {
        var trigger = e.relatedTarget;
        if (!trigger) return;
        frame.src = trigger.getAttribute('data-doc-url') || '';
        title.textContent = trigger.getAttribute('data-doc-title') || 'Document';
      });
      modalEl.addEventListener('hidden.bs.modal', function () {
        frame.src = '';
      });
    })();
  </script>
<?php if (!empty($is_basics_admin_page)): ?>
  <!-- DataTables (search/sort) + Buttons (print) — every table on the Basics admin side. -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.11/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.3/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.3/js/buttons.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.3/js/buttons.print.min.js"></script>
  <script>
  $(function () {
    document.querySelectorAll('table.table-theme:not(.no-datatable)').forEach(function (table) {
      var $table = $(table);

      // Leave a genuinely empty table ("No orders found." etc.) as a plain
      // message — DataTables' pagination/info chrome around one placeholder
      // row is just noise.
      var $bodyRows = $table.find('> tbody > tr');
      if ($bodyRows.length <= 1 && $bodyRows.find('> td[colspan]').length > 0) {
        return;
      }

      // Any <th class="no-print"> (the actions column, everywhere it's used)
      // holds buttons/forms, not sortable or searchable data.
      var columnDefs = [];
      table.querySelectorAll('thead th').forEach(function (th, idx) {
        if (th.classList.contains('no-print')) {
          columnDefs.push({ targets: idx, orderable: false, searchable: false });
        }
      });

      $table.DataTable({
        columnDefs: columnDefs,
        order: [],
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        dom: "<'d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3'B f>" +
             "rt" +
             "<'d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3'l i p>",
        language: {
          search: '',
          searchPlaceholder: 'Search this table…',
          lengthMenu: 'Show _MENU_',
          info: 'Showing _START_–_END_ of _TOTAL_',
          infoEmpty: 'No entries',
          infoFiltered: '(filtered from _MAX_)',
          zeroRecords: 'No matching entries found.',
          paginate: { previous: '‹', next: '›' }
        },
        buttons: [
          { extend: 'print', text: '<i class="fas fa-print"></i> Print', className: 'btn-outline-theme no-print', title: document.title, exportOptions: { columns: ':not(.no-print)' } }
        ]
      });
    });
  });
  </script>
<?php endif; ?>
</body>
</html>
