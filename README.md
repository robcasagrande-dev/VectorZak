# VectorZak

Integration between Vector POS and ZaK PMS.

## Local Development Setup

Follow these steps to configure the project locally on your machine.

### Prerequisites
- PHP 8.0 or higher
- [Composer](https://getcomposer.org/)

### Installation Steps

1. **Clone the repository** into your usual `Projects` folder:
   ```bash
   cd ~/Projects
   git clone https://github.com/robcasagrande-dev/VectorZak.git
   cd VectorZak
   ```

2. **Install PHP Dependencies** using Composer:
   ```bash
   composer install
   ```

3. **Configure the Environment**:

   **Automated Method (Recommended):**
   This project is integrated with the `SmartCheckIn Suite` centralized configuration system.
   1. Ensure the `SmartCheckInSuite` repository is cloned in the same `~/Projects` directory.
   2. Edit `~/Projects/SmartCheckIn Suite/SharedConfig/config.json` to include your VectorZak credentials (`ZAK_API_KEY`, `ZAK_LCODE`, `VECTOR_APP_USER`, `VECTOR_APP_PASS`).
   3. Run the sync script from the `SharedConfig` directory:
      ```bash
      cd ~/Projects/SmartCheckIn\ Suite/SharedConfig
      python sync_config.py
      ```
      This will automatically generate the `config.php` file in the VectorZak folder.

   **Manual Method:**
   Alternatively, you can configure it manually by copying the example file:
   ```bash
   cd ~/Projects/VectorZak
   cp config.example.php config.php
   ```
   Open `config.php` and replace the placeholder values with your actual ZaK API credentials and App password.

4. **Run a Local Development Server**:
   You can use PHP's built-in web server to test the application locally.
   ```bash
   php -S localhost:8000
   ```
   The application will now be available at: [http://localhost:8000](http://localhost:8000)

## Security Note
Never commit your `config.php` to version control. It is already added to `.gitignore` to prevent accidentally exposing your API keys.
