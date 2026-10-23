<?php

/**
 * Profile Completion Modal Component
 *
 * Shows on first login for users with incomplete profiles
 * Collects: First Name, Last Name, Username (Display Name), Region
 */

?>

<!-- Profile Completion Modal -->
<div id="profileCompletionModal"
     class="modal profile-completion-modal"
     role="dialog"
     aria-labelledby="profileCompletionModal-title"
     aria-describedby="profileCompletionModal-desc"
     aria-hidden="true"
     data-backdrop="static">
  <div class="modal-content" tabindex="-1">

    <div class="modal-header">
      <h2 id="profileCompletionModal-title">Complete Your Profile</h2>
      <p id="profileCompletionModal-desc" class="modal-description">
        Help us personalize your experience by completing your profile information.
      </p>
    </div>

    <div class="modal-body">
      <form id="profileCompletionForm" novalidate>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="complete_profile">

        <!-- First Name -->
        <div class="form-group">
          <label for="profile_first_name" class="required">First Name</label>
          <input type="text"
                 id="profile_first_name"
                 name="first_name"
                 required
                 maxlength="80"
                 autocomplete="given-name"
                 aria-describedby="firstNameHelp">
          <div id="firstNameHelp" class="form-help">
            Enter your first name (1-80 characters)
          </div>
        </div>

        <!-- Last Name -->
        <div class="form-group">
          <label for="profile_last_name" class="required">Last Name</label>
          <input type="text"
                 id="profile_last_name"
                 name="last_name"
                 required
                 maxlength="80"
                 autocomplete="family-name"
                 aria-describedby="lastNameHelp">
          <div id="lastNameHelp" class="form-help">
            Enter your last name (1-80 characters)
          </div>
        </div>

        <!-- Username (Display Name) -->
        <div class="form-group">
          <label for="profile_username" class="required">Username (Display Name)</label>
          <input type="text"
                 id="profile_username"
                 name="username"
                 required
                 minlength="3"
                 maxlength="50"
                 pattern="[a-zA-Z0-9._\-]+"
                 autocomplete="username"
                 aria-describedby="usernameHelp">
          <div id="usernameHelp" class="form-help">
            Choose a display name (3-50 characters, alphanumeric plus . _ -)
          </div>
        </div>

        <!-- Region Selection -->
        <div class="form-group">
          <label for="profile_region" class="required">Region</label>
          <select id="profile_region"
                  name="region_id"
                  required
                  aria-describedby="regionHelp">
            <option value="">Select your region...</option>
            <!-- Populated dynamically by JavaScript -->
          </select>
          <div id="regionHelp" class="form-help">
            Select the region where you operate
          </div>
        </div>
      </form>
    </div>

    <div class="modal-footer">
      <button type="button"
              class="btn btn-secondary"
              id="profileCompletionDismiss">
        Complete Later
      </button>
      <button type="submit"
              class="btn btn-primary"
              id="profileCompletionSubmit"
              form="profileCompletionForm">
        Save Profile
      </button>
    </div>
  </div>
</div>
