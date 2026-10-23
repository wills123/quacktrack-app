<?php
// app/views/footer.php
?>
</main>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Enhanced modal accessibility
  const modals = document.querySelectorAll('.modal');
  modals.forEach(modal => {
    const closeButton = modal.querySelector('.close-button');

    if (closeButton) {
      closeButton.addEventListener('click', function() {
        modal.setAttribute('aria-hidden', 'true');
        modal.style.display = 'none';

        // Return focus to the element that opened the modal
        const trigger = document.querySelector(`[data-modal-target="${modal.id}"]`);
        if (trigger) {
          trigger.focus();
        }
      });
    }

    // Close modal on escape key
    modal.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        modal.setAttribute('aria-hidden', 'true');
        modal.style.display = 'none';

        // Return focus to the element that opened the modal
        const trigger = document.querySelector(`[data-modal-target="${modal.id}"]`);
        if (trigger) {
          trigger.focus();
        }
      }
    });

    // Focus trap for modals
    modal.addEventListener('keydown', function(event) {
      if (event.key === 'Tab') {
        const focusableElements = modal.querySelectorAll(
          'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (event.shiftKey) {
          if (document.activeElement === firstElement) {
            lastElement.focus();
            event.preventDefault();
          }
        } else {
          if (document.activeElement === lastElement) {
            firstElement.focus();
            event.preventDefault();
          }
        }
      }
    });
  });

  // Enhanced form validation announcements
  const forms = document.querySelectorAll('form');
  forms.forEach(form => {
    form.addEventListener('submit', function(event) {
      const invalidFields = form.querySelectorAll(':invalid');

      if (invalidFields.length > 0) {
        event.preventDefault();

        // Create or update error summary
        let errorSummary = form.querySelector('.error-summary');
        if (!errorSummary) {
          errorSummary = document.createElement('div');
          errorSummary.className = 'error-summary';
          errorSummary.setAttribute('role', 'alert');
          errorSummary.setAttribute('aria-live', 'assertive');
          form.insertBefore(errorSummary, form.firstChild);
        }

        const errorList = document.createElement('ul');
        errorSummary.innerHTML = '<h2>Please fix the following errors:</h2>';
        errorSummary.appendChild(errorList);

        invalidFields.forEach((field, index) => {
          const label = form.querySelector(`label[for="${field.id}"]`) ||
                       field.closest('label') ||
                       { textContent: field.name || `Field ${index + 1}` };

          const listItem = document.createElement('li');
          const link = document.createElement('a');
          link.href = `#${field.id}`;
          link.textContent = `${label.textContent}: ${field.validationMessage}`;
          link.addEventListener('click', function(e) {
            e.preventDefault();
            field.focus();
          });

          listItem.appendChild(link);
          errorList.appendChild(listItem);
        });

        // Focus the error summary
        errorSummary.focus();
        errorSummary.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  });

  // Announce dynamic content changes
  function announceToScreenReader(message, priority = 'polite') {
    const announcement = document.createElement('div');
    announcement.setAttribute('aria-live', priority);
    announcement.setAttribute('aria-atomic', 'true');
    announcement.className = 'sr-only';
    announcement.textContent = message;

    document.body.appendChild(announcement);

    // Remove after announcement
    setTimeout(() => {
      document.body.removeChild(announcement);
    }, 1000);
  }

  // Make announceToScreenReader globally available
  window.announceToScreenReader = announceToScreenReader;
});
</script>
<?php if (!empty($extra_scripts) && is_array($extra_scripts)) : ?>
    <?php foreach ($extra_scripts as $scriptSrc) : ?>
    <script src="<?= h(asset_href($scriptSrc)) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
