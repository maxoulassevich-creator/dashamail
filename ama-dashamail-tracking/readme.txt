=== Amarèssence — DashaMail Tracking ===
Contributors: amaressence
Tags: woocommerce, dashamail, cdp, abandoned cart
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.9.2
License: GPLv2 or later

== Description ==
Передаёт в DashaMail события:
* ViewProduct / ViewCategory
* cart.addProduct / cart.removeProduct / cart.setProductQuantity / cart.clear
* PromoActivated
* OrderCreated / OrderPaid / OrderClosed
* UserRegistration / UserIdentifier / UserSubscribe

Адаптирован к присланным плагинам:
* Foneona Woo Layout 1.8.7 — шорткоды [foneona_cart] и [foneona_checkout]
* RELOD Auth Forms 1.1.0 — шорткод [relod_auth_form]

== Installation ==
1. Загрузите ZIP через Плагины → Добавить плагин → Загрузить плагин.
2. Активируйте.
3. Откройте WooCommerce → DashaMail Tracking.
4. Для текущего фида оставьте режим «Как в текущем YML Amarèssence».
5. Введите идентификатор точки подписки DashaMail, если требуется событие UserSubscribe.
6. В DashaMail включите тестовый режим и последовательно проверьте события.

== Abandoned cart ==
В DashaMail сценарий строится как:
«Добавил продукт в корзину» И НЕ «Создан заказ» в следующие X минут.
Для товарных карточек необходимо добавить YML-фид. ID productId должен совпадать с offer id.

== Important limitation ==
DashaMail на странице разработчика показывает отдельные варианты кода для фронтенда и бэкенда. Этот релиз использует официальный фронтенд-трекер. События OrderPaid/OrderClosed, возникшие без активного браузера покупателя, сохраняются и отправляются при следующем визите зарегистрированного покупателя или при возврате гостя на страницу заказа. Для гарантированной немедленной серверной доставки этих событий необходимо добавить backend-метод DashaMail после получения кода/идентификатора/секретного ключа из вкладки «Бэкенд».

== Changelog ==
= 0.9.2 =
* Добавлен точный режим ID для текущего YML-фида Amarèssence.
* Вариации передают собственный SKU, а при пустом SKU — SKU родителя + дефис + ID вариации.
* Исправлено получение собственного SKU вариации без наследования SKU родителя.

= 0.9.1 =
* Первая версия.
