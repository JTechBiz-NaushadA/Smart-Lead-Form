document.addEventListener("DOMContentLoaded", function () {

    // ===========================
    // Test Email Button
    // ===========================
    const testBtn = document.getElementById("tx-send-test");
    if (testBtn) {
        testBtn.addEventListener("click", function () {

            const emailField = document.getElementById("tx-test-email");
            const email = emailField.value.trim();
            const msgBox = document.getElementById("tx-test-msg");

            // Clear previous message
            msgBox.innerText = "";
            msgBox.style.color = "";

            // Basic email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!email) {
                msgBox.innerText = "Please enter email";
                msgBox.style.color = "red";
                return;
            }
            if (!emailRegex.test(email)) {
                msgBox.innerText = "Please enter a valid email address";
                msgBox.style.color = "red";
                return;
            }

            // Disable button
            testBtn.disabled = true;
            testBtn.innerText = "Sending...";

            // Prepare form data
            const formData = new URLSearchParams();
            formData.append("action", "tx_send_test");
            formData.append("email", email);
            formData.append("nonce", txAdmin.nonce);

            // Send request
            fetch(txAdmin.ajaxurl, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: formData.toString()
            })
            .then(res => res.json())
            .then(res => {
                msgBox.innerText = res.data;
                msgBox.style.color = res.success ? "green" : "red";
            })
            .catch(() => {
                msgBox.innerText = "Something went wrong";
                msgBox.style.color = "red";
            })
            .finally(() => {
                testBtn.disabled = false;
                testBtn.innerText = "Send Test";
            });

        });
    }

    // ===========================
    // Delete Lead Buttons
    // ===========================
    const deleteBtns = document.querySelectorAll(".tx-delete-lead");
	deleteBtns.forEach(btn => {
		btn.addEventListener("click", function () {
			if (!confirm("Are you sure you want to delete this lead?")) return;

			const leadId = this.dataset.id;
			const row = this.closest("tr");
			const nonce = txAdmin.nonce;

			btn.disabled = true;
			const originalText = btn.innerText;
			btn.innerText = "Deleting...";

			fetch(txAdmin.ajaxurl, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: new URLSearchParams({
					action: "tx_delete_lead",
					id: leadId,
					nonce: nonce
				})
			})
			.then(res => res.json())
			.then(res => {
				if (res.success) {
					row.remove();
					alert(res.data);
				} else {
					alert(res.data);
				}
			})
			.catch(() => alert("Something went wrong"))
			.finally(() => {
				btn.disabled = false;
				btn.innerText = originalText;
			});
		});
	});

});