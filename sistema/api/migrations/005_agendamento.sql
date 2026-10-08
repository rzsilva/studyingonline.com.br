-- Agendamento (atendimento): a tabela do legado só tinha as referências; o sistema novo guarda data, hora e descrição.
ALTER TABLE AGENDAMENTO
    ADD COLUMN DATA DATE NULL,
    ADD COLUMN HORA TIME NULL,
    ADD COLUMN OUTROS VARCHAR(200) NULL,
    ADD COLUMN DESCRICAO TEXT NULL;
