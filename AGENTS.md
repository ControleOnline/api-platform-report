## Escopo
- Modulo de reports e visoes consolidadas da API.
- Serve para recursos agregados e leitura resumida de dados operacionais.

## Quando usar
- Prompts sobre indicadores, cards de report, endpoints de resumo e consultas agregadas.

## Limites
- Evitar colocar regra de escrita principal aqui.
- Sempre que possivel, `report` deve consumir dados dos modulos de dominio em vez de virar dono deles.
- O endpoint `/report/orders/operational-insights` deve apenas orquestrar filtros e serializacao do summary; as queries de agregacao pertencem ao dominio `orders`, especialmente ao `OrderRepository`.
- Cards operacionais da TV devem buscar este endpoint e consumir apenas metrica de operacao, sem campos financeiros.
