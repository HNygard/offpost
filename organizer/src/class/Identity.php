<?php

class IdentityRepository {

    /**
     * @var PDO
     */
    var $connection;

    public function __construct(PDO $connection) {
        $this->connection = $connection;
    }

    /**
     * @return Identity[]
     */
    public function getIdentities() {
        $query = $this->connection->prepare('
            SELECT *
            FROM identities
        ');
        $query->execute();

        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $identities = array();
        foreach ($rows as $row) {
            $identity = new Identity();
            $identity->bcc = $row['bcc'];
            $identity->changed = $row['changed'];
            $identity->del = $row['del'];
            $identity->email = $row['email'];
            $identity->html_signature = $row['html_signature'];
            $identity->identity_id = $row['identity_id'];
            $identity->name = $row['name'];
            $identity->organization = $row['organization'];
            $identity->reply_to = $row['reply-to'];
            $identity->signature = $row['signature'];
            $identity->standard = $row['standard'];
            $identity->user_id = $row['user_id'];
            $identities[] = $identity;
        }
        return $identities;
    }

    public function createIdentity($my_name, $my_email) {
        $query = $this->connection->prepare('INSERT INTO `identities`
            (`identity_id`, `user_id`, `changed`,
            `del`, `standard`,
            `name`, `organization`, `email`, `reply-to`, `bcc`, `signature`, `html_signature`)
            VALUES (NULL, \'1\', \'1000-01-01 00:00:00.000000\',
            \'0\', \'0\',
            :name,
            \'\',
            :email, \'\', \'\', :signature, :html_signature);');
        $query->bindValue(':name', $my_name, PDO::PARAM_STR);
        $query->bindValue(':email', $my_email, PDO::PARAM_STR);
        $query->bindValue(':signature', $my_name, PDO::PARAM_STR);
        $query->bindValue(':html_signature', $my_name, PDO::PARAM_STR);
        $query->execute();
    }

}

class Identity {
    public $bcc;
    public $changed;
    public $del;
    public $email;
    public $html_signature;
    public $identity_id;
    public $name;
    public $organization;
    public $reply_to;
    public $signature;
    public $standard;
    public $user_id;
}