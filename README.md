# Soundwave

![WordPress](https://img.shields.io/badge/WordPress-Plugin-blue?logo=wordpress&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-Sync-purple?logo=woocommerce&logoColor=white)
![Version](https://img.shields.io/badge/version-1.1.10-brightgreen)
![License](https://img.shields.io/badge/license-GPL--2.0+-orange)

---

## 📌 Overview

**Soundwave** is a custom WooCommerce plugin that automatically **syncs orders from one WordPress site to another**.  
It’s designed for affiliate/subscription sites that forward orders to a central hub (e.g., **thebeartraxs.com**) for fulfillment.

---

## ✨ Features

- 🔄 **Automatic Order Sync** – new orders push instantly to the destination site  
- 🖥 **Admin Dashboard** – manage settings & view sync logs in WordPress admin  
- ✅ **Manual Sync Button** – retry syncs from the order list  
- 🚫 **Duplicate Protection** – prevents syncing the same order twice  
- 📦 **Full Order Data** – products, SKUs, images, quantities, descriptions  
- 👤 **Customer Info** – transfers customer name, email, and address  
- 💬 **Order Notes & Coupons** – included in the sync  
- 📧 **Email Triggers** – synced orders trigger destination WooCommerce emails  
- 🔁 **Retry System** – failed syncs stay queued until successful  

---

## ⚙️ Installation

1. Download the latest release zip (e.g., `soundwave-v1.1.10.zip`).
2. Go to **Plugins → Add New → Upload Plugin** in WordPress admin.
3. Upload and install the zip file.
4. Click **Activate Plugin**.
5. The **Soundwave** menu will now appear in your sidebar.

---

## 🛠 Configuration

1. Go to **Soundwave → Settings**.  
2. Enter your **Destination Site URL**.  
3. Add your **API keys** from the destination site.  
4. Save changes — you’re ready to sync.  

---

## 🚀 Usage

- New WooCommerce orders are synced automatically.  
- Manual sync is available in **WooCommerce → Orders**.  
- Synced orders show as **completed** and can’t be retried manually.  

---

## 📂 File Structure

soundwave/
├── soundwave.php # Main plugin loader
├── includes/
│ ├── soundwave-sync.php # Order sync logic
│ ├── soundwave-admin.php # Admin dashboard & settings
│ ├── email-render-handler.php # Email behavior on sync
│ └── remove-email-product-image.php # Adjusts WooCommerce email images
└── assets/
├── css/ # Admin styles
└── js/ # Admin scripts


---

## 📝 Changelog

### v1.1.10
- Disabled manual sync button for already-synced orders  
- Fixed duplication issues during sync  
- Improved handling of SKU, images, quantity, and customer info  

### v1.1.9
- Added retry system for failed syncs  
- Added admin sync status feedback  
- Triggered WooCommerce emails on destination site  

---

## 🤝 Contributing

Pull requests are welcome!  
Fork the repo, create a feature branch, and open a PR.

---

## 📄 License

Licensed under the **GPL-2.0+** license.  
You may freely modify and redistribute under the same terms.
