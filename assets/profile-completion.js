/**
 * Profile Completion Modal Handler
 * Checks if user needs to complete profile and shows modal
 */
/* global ButtonLoadingManager */

(function() {
    'use strict';

    const ProfileCompletion = {
        modal: null,
        form: null,
        csrfToken: null,

        init() {
            // Only run on dashboard/index page
            if (window.location.pathname !== '/' && window.location.pathname !== '/index.php') {
                return;
            }

            this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (!this.csrfToken) {
                console.error('CSRF token not found');
                return;
            }

            this.modal = document.getElementById('profileCompletionModal');
            this.form = document.getElementById('profileCompletionForm');

            if (!this.modal || !this.form) {
                return; // Modal not present on page
            }

            this.setupEventListeners();
            this.checkProfileStatus();
        },

        setupEventListeners() {
            // Form submission
            this.form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.submitProfile();
            });

            // Dismiss button
            const dismissBtn = document.getElementById('profileCompletionDismiss');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', () => {
                    this.dismissModal();
                });
            }

            // Prevent closing modal by clicking backdrop
            this.modal.addEventListener('click', (e) => {
                if (e.target === this.modal) {
                    e.stopPropagation();
                    // Shake the modal to indicate it can't be closed
                    this.modal.querySelector('.modal-content')?.classList.add('shake');
                    setTimeout(() => {
                        this.modal.querySelector('.modal-content')?.classList.remove('shake');
                    }, 500);
                }
            });

            // Prevent ESC key from closing modal
            this.modal.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    e.stopPropagation();
                }
            });
        },

        async checkProfileStatus() {
            try {
                const formData = new FormData();
                formData.append('action', 'check_completion');
                formData.append('csrf', this.csrfToken);

                const response = await fetch('/api/profile_completion.php', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': this.csrfToken
                    },
                    body: formData
                });

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Failed to check profile status');
                }

                if (data.needs_completion) {
                    this.populateRegions(data.regions || []);
                    this.showModal();
                }

            } catch (error) {
                console.error('Profile completion check failed:', error);
                // Fail silently - don't block user from using the app
            }
        },

        populateRegions(regions) {
            const select = document.getElementById('profile_region');
            if (!select) {
                return;
            }

            // Clear existing options except the first (placeholder)
            while (select.options.length > 1) {
                select.remove(1);
            }

            // Add region options
            regions.forEach(region => {
                const option = document.createElement('option');
                option.value = region.id;
                option.textContent = region.name;
                select.appendChild(option);
            });
        },

        showModal() {
            if (window.ModalManager) {
                window.ModalManager.show(this.modal);
            } else {
                // Fallback if ModalManager not loaded
                this.modal.style.display = 'flex';
                this.modal.classList.add('show');
                document.body.classList.add('modal-open');
            }
        },

        dismissModal() {
            if (window.ModalManager) {
                window.ModalManager.hide(this.modal);
            } else {
                this.modal.classList.remove('show');
                setTimeout(() => {
                    this.modal.style.display = 'none';
                    document.body.classList.remove('modal-open');
                }, 300);
            }

            // Show friendly reminder
            if (window.QuackNotify) {
                QuackNotify.info(
                    'You can complete your profile anytime from your account settings.',
                    { duration: 6000 }
                );
            }
        },

        async submitProfile() {
            const submitBtn = document.getElementById('profileCompletionSubmit');

            // Client-side validation
            if (!this.form.checkValidity()) {
                this.form.reportValidity();
                return;
            }

            // Show loading state
            if (window.ButtonLoadingManager) {
                ButtonLoadingManager.setLoading(submitBtn, 'Saving...');
            } else {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving...';
            }

            try {
                const formData = new FormData(this.form);

                const response = await fetch('/api/profile_completion.php', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': this.csrfToken
                    },
                    body: formData
                });

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Failed to save profile');
                }

                // Success!
                if (window.ButtonLoadingManager) {
                    ButtonLoadingManager.setSuccess(submitBtn, 'Saved!');
                }

                if (window.QuackNotify) {
                    QuackNotify.success(data.message || 'Profile completed successfully!');
                }

                // Close modal after short delay
                setTimeout(() => {
                    this.dismissModal();

                    // Reload page to update user display name in header
                    setTimeout(() => {
                        window.location.reload();
                    }, 500);
                }, 1000);

            } catch (error) {
                console.error('Profile save failed:', error);

                if (window.ButtonLoadingManager) {
                    ButtonLoadingManager.setError(submitBtn, 'Error!');
                } else {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Save Profile';
                }

                if (window.QuackNotify) {
                    QuackNotify.error(error.message || 'Failed to save profile. Please try again.');
                } else {
                    alert(error.message || 'Failed to save profile. Please try again.');
                }
            }
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ProfileCompletion.init());
    } else {
        ProfileCompletion.init();
    }

    // Export for testing/debugging
    window.ProfileCompletion = ProfileCompletion;

})();
