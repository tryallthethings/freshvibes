# FreshVibes: an iGoogle / Netvibes-like extension for FreshRSS

A modern, customizable dashboard view extension for [FreshRSS](https://freshrss.org/) that looks and feels like iGoogle / NetVibes.  
With the recent shutdown of Netvibes I was looking for an alternative. FreshRSS was recommended to me, but it unfortunately lacked the dashboard I was used to. So I created my own extension that brings the functionality to FreshRSS.
<br /><br />

<p align="center">
    <b>Dashboard in dark and category tabs mode</b>
</p>
<p align="center">
    <img src="https://github.com/user-attachments/assets/a1e737d0-3886-4cf0-a4b3-59988281ffa4" width="80%">
</p>

<p align="center">
    <b>Dashboard in light and custom tabs mode</b>
</p>
<p align="center">
    <img src="https://github.com/user-attachments/assets/972ad955-ca82-48b1-91f7-14232245b382" width="80%">
</p>

<p align="center">
    <b>Article excerpt in modal</b>
</p>
<p align="center">
    <img src="https://github.com/user-attachments/assets/de2c71ba-d16d-42a1-8203-8a2993275f58" width="80%">
</p>

## ✨ Features

### 📊 Dashboard Views
**The extension offers two different view modes:**
- **Custom Tabs Mode**: Organize your feeds into custom tabs with drag-and-drop functionality
- **Category Tabs Mode**: Automatically create tabs based on your existing FreshRSS categories

### 🎨 Customization Options
- **Multi-column Layouts**: Configure 1-6 columns per tab for optimal feed organization
- **Tab Customization**:
    - Custom icons/emojis for each tab
    - Configurable background colors with automatic contrast adjustment
    - Rename tabs with double-click  
 
<img src="https://github.com/user-attachments/assets/848771d7-4ef9-4b02-a7c1-75a98e7c37b2" width="400px">

- **Feed Settings**:
    - Adjust the amount of articles shown for each feed
    - Multiple font sizes for each feed
    - Custom header colors for each feed
 
<img src="https://github.com/user-attachments/assets/2e0ad702-6657-4f7b-a514-322ed66032ce" width="400px">

### 📱 User Experience
- **Article Preview Modal**: Quickly peek into any article with a modal
- **Auto-refresh**: Optional automatic dashboard refresh at configurable intervals
- **Responsive Design**: Partly mobile-optimized layout
- **Multi-language Support**: Currently available in English and German

## 📋 Requirements

- FreshRSS 1.16.0 or higher
- Modern web browser with JavaScript enabled

## 🚀 Installation

1. Download the latest [Release](https://github.com/tryallthethings/freshvibes/releases)
2. Extract the .ZIP-file and copy / upload the contents to /path/to/freshrss/extensions/

_or via command line:_
1. Navigate to your FreshRSS extensions folder:
  ```bash
  cd /path/to/freshrss/extensions/
  ```

2. Clone this repository
  ```bash
  git clone https://github.com/tryallthethings/freshvibes.git
  ```

3. In FreshRSS:
    - Go to **Configuration** → **Extensions**
    - Find "Fresh Vibes View" in the list
    - Click **Enable**

## 🎯 Usage

### Getting Started
1. After enabling the extension, click the **📊** icon in the reading modes toolbar
2. Choose your preferred view mode in the extension settings.

### Managing Tabs
- **Add Tab**: Click the **+** button (only available in custom tabs dashboard mode)
- **Rename Tab**: Double-click the tab name (only available in custom tabs dashboard mode)
- **Configure Tab**: Click the **▾** arrow to access:
 - Column layout (1-6 columns)
 - Tab icon and color
 - Tab header background color

### Configuring Feeds
- Click the **⚙️** icon on any feed to adjust:
 - Feed article limit
 - Feed font size
 - Feed header color
- Drag feeds between columns or tabs to reorganize

## ⚙️ Configuration

Access the extension settings through **Configuration** → **Extensions** → **Fresh Vibes View**:

- **Dashboard Mode**: Choose between Custom Tabs or Category Tabs
- **Auto-refresh**: Enable/disable automatic refresh
- **Refresh Interval**: Set refresh time in minutes (default: 15)
- **Date Format**: Customize date display format (e.g., `Y-m-d H:i`)

## 🎨 Screenshots

*[Screenshots to be added]*

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 🐛 Issues

If you encounter any bugs or have feature requests, please [open an issue](https://github.com/tryallthethings/freshvibes/issues).

## 👏 Acknowledgments

- Inspired by the classic Netvibes dashboard interface
- Built for the [FreshRSS](https://freshrss.org/) community
- Uses [Sortable.js](https://sortablejs.github.io/Sortable/) for drag-and-drop functionality
