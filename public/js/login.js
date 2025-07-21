// Login Page JavaScript
document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.getElementById('loginForm');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const errorMessage = document.getElementById('errorMessage');
    const loginBtn = loginForm.querySelector('button[type="submit"]');
    const btnText = loginBtn.querySelector('.btn-text');
    const btnLoading = loginBtn.querySelector('.btn-loading');

    // Check if already logged in
    if (window.authSystem.isAuthenticated()) {
        const user = window.authSystem.getCurrentUser();
        redirectUser(user);
        return;
    }

    // Handle form submission
    loginForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const email = emailInput.value.trim();
        const password = passwordInput.value.trim();

        if (!email || !password) {
            showError('Please enter both email and password');
            return;
        }

        // Show loading state
        setLoading(true);
        hideError();

        try {
            const result = await window.authSystem.login(email, password);
            
            if (result.success) {
                // Show success animation
                showSuccess();
                
                // Redirect after short delay
                setTimeout(() => {
                    redirectUser(result.user);
                }, 1000);
            } else {
                showError(result.message || 'Login failed');
                setLoading(false);
            }
        } catch (error) {
            console.error('Login error:', error);
            showError('An error occurred. Please try again.');
            setLoading(false);
        }
    });

    // Helper functions
    function setLoading(loading) {
        loginBtn.disabled = loading;
        if (loading) {
            btnText.style.display = 'none';
            btnLoading.style.display = 'flex';
            loginBtn.style.background = 'linear-gradient(135deg, #6b7280 0%, #9ca3af 100%)';
        } else {
            btnText.style.display = 'flex';
            btnLoading.style.display = 'none';
            loginBtn.style.background = 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%)';
        }
    }

    function showError(message) {
        errorMessage.innerHTML = `
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                <line x1="15" y1="9" x2="9" y2="15" stroke="currentColor" stroke-width="2"/>
                <line x1="9" y1="9" x2="15" y2="15" stroke="currentColor" stroke-width="2"/>
            </svg>
            ${message}
        `;
        errorMessage.style.display = 'flex';
        errorMessage.classList.add('fade-in');
        
        // Shake the form
        loginForm.style.animation = 'shake 0.5s ease-in-out';
        setTimeout(() => {
            loginForm.style.animation = '';
        }, 500);
    }

    function hideError() {
        errorMessage.style.display = 'none';
        errorMessage.classList.remove('fade-in');
    }

    function showSuccess() {
        loginBtn.innerHTML = `
            <span style="display: flex; align-items: center; gap: 8px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Success! Redirecting...
            </span>
        `;
        loginBtn.style.background = 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
    }

    function redirectUser(user) {
        if (user.role === 'manager') {
            window.location.href = 'pages/manager-dashboard.html';
        } else {
            window.location.href = 'pages/team-dashboard.html';
        }
    }

    // Auto-fill demo credentials
    window.fillCredentials = function(type) {
        const credentials = {
            manager: { email: 'asalpandey44@gmail.com', password: 'PLMokn!@#123' },
            team1: { email: 'aniket@techcorp.com', password: 'aniket123' },
            team2: { email: 'achyut@techcorp.com', password: 'achyut123' },
            team3: { email: 'bishal@techcorp.com', password: 'bishal123' }
        };

        if (credentials[type]) {
            emailInput.value = credentials[type].email;
            passwordInput.value = credentials[type].password;
            
            // Add visual feedback
            emailInput.style.background = 'rgba(59, 130, 246, 0.1)';
            passwordInput.style.background = 'rgba(59, 130, 246, 0.1)';
            
            setTimeout(() => {
                emailInput.style.background = '';
                passwordInput.style.background = '';
            }, 1000);
            
            emailInput.focus();
        }
    };

    // Input validation and styling
    [emailInput, passwordInput].forEach(input => {
        input.addEventListener('input', function() {
            if (this.value.trim()) {
                hideError();
                this.style.borderColor = '#10b981';
                this.parentElement.querySelector('.input-icon').style.color = '#10b981';
            } else {
                this.style.borderColor = '#e5e7eb';
                this.parentElement.querySelector('.input-icon').style.color = '#9ca3af';
            }
        });

        input.addEventListener('focus', function() {
            this.parentElement.style.transform = 'scale(1.02)';
        });

        input.addEventListener('blur', function() {
            this.parentElement.style.transform = 'scale(1)';
        });
    });

    // Enter key handling
    passwordInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            loginForm.dispatchEvent(new Event('submit'));
        }
    });

    // Add floating animation to login card
    const loginCard = document.querySelector('.login-card');
    let mouseX = 0;
    let mouseY = 0;

    document.addEventListener('mousemove', function(e) {
        mouseX = (e.clientX / window.innerWidth) * 2 - 1;
        mouseY = (e.clientY / window.innerHeight) * 2 - 1;
        
        loginCard.style.transform = `
            perspective(1000px) 
            rotateY(${mouseX * 2}deg) 
            rotateX(${mouseY * -2}deg) 
            translateZ(0)
        `;
    });

    // Reset transform when mouse leaves
    document.addEventListener('mouseleave', function() {
        loginCard.style.transform = 'perspective(1000px) rotateY(0deg) rotateX(0deg) translateZ(0)';
    });
});