// Theme management script
(function() {
    // Check local storage or system preference
    const savedTheme = localStorage.getItem("theme");
    const systemPrefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
    
    if (savedTheme === "dark" || (!savedTheme && systemPrefersDark)) {
        document.documentElement.classList.add("dark");
    } else {
        document.documentElement.classList.remove("dark");
    }
})();

document.addEventListener("DOMContentLoaded", function() {
    const themeButtons = document.querySelectorAll(".theme-switch-btn");
    if (themeButtons.length === 0) return;

    function updateToggleUI() {
        const isDark = document.documentElement.classList.contains("dark");
        
        themeButtons.forEach(btn => {
            const theme = btn.dataset.theme;
            if ((theme === "dark" && isDark) || (theme === "light" && !isDark)) {
                btn.classList.add("active");
            } else {
                btn.classList.remove("active");
            }
        });
    }

    // Initialize toggle button state
    updateToggleUI();

    // Add click event listener to each button
    themeButtons.forEach(btn => {
        btn.addEventListener("click", function(e) {
            e.preventDefault();
            const targetTheme = this.dataset.theme;
            
            if (targetTheme === "dark") {
                document.documentElement.classList.add("dark");
                localStorage.setItem("theme", "dark");
            } else {
                document.documentElement.classList.remove("dark");
                localStorage.setItem("theme", "light");
            }
            
            updateToggleUI();
        });
    });
});
