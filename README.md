LogAnalyzer
===========

Analyzátor logů využívající sqlite databázi.

Napojení na aplikaci
--------------------
Instalace pomocí composeru

	clevis/loganalyzer


Přidat do Configurator->onInitFormControls()

```php
Nette\Forms\Container::extensionMethod('addDatePicker', function ($container, $name, $label = NULL) {
	return $container[$name] = new \Nextras\Forms\Controls\DatePicker($label);
});
```
