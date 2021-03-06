<?php

namespace Lexik\Bundle\JWTAuthenticationBundle\Services;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\HeaderAwareJWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTEncodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Provides convenient methods to manage JWT creation/verification.
 *
 * @author Nicolas Cabot <n.cabot@lexik.fr>
 * @author Robin Chalas <robin.chalas@gmail.com>
 */
class JWTManager implements JWTManagerInterface, JWTTokenManagerInterface
{
    /**
     * @var JWTEncoderInterface
     */
    protected $jwtEncoder;

    /**
     * @var BackwardsCompatibleEventDispatcher
     */
    protected $dispatcher;

    /**
     * @var string
     */
    protected $userIdentityField;

    /**
     * @var string
     */
    protected $userIdClaim;

    /**
     * @param JWTEncoderInterface      $encoder
     * @param EventDispatcherInterface $dispatcher
     * @param string|null              $userIdClaim
     */
    public function __construct(JWTEncoderInterface $encoder, EventDispatcherInterface $dispatcher, $userIdClaim = null)
    {
        $this->jwtEncoder        = $encoder;
        $this->dispatcher        = BackwardsCompatibleEventDispatcher::create($dispatcher);
        $this->userIdentityField = 'username';
        $this->userIdClaim       = $userIdClaim;
    }

    /**
     * {@inheritdoc}
     */
    public function create(UserInterface $user)
    {
        $payload = ['roles' => $user->getRoles()];
        $this->addUserIdentityToPayload($user, $payload);

        $jwtCreatedEvent = new JWTCreatedEvent($payload, $user);
        $this->dispatcher->dispatch($jwtCreatedEvent, Events::JWT_CREATED);

        if ($this->jwtEncoder instanceof HeaderAwareJWTEncoderInterface) {
            $jwtString = $this->jwtEncoder->encode($jwtCreatedEvent->getData(), $jwtCreatedEvent->getHeader());
        } else {
            $jwtString = $this->jwtEncoder->encode($jwtCreatedEvent->getData());
        }

        $jwtEncodedEvent = new JWTEncodedEvent($jwtString);
        $this->dispatcher->dispatch($jwtEncodedEvent, Events::JWT_ENCODED);

        return $jwtString;
    }

    /**
     * {@inheritdoc}
     */
    public function decode(TokenInterface $token)
    {
        if (!($payload = $this->jwtEncoder->decode($token->getCredentials()))) {
            return false;
        }

        $event = new JWTDecodedEvent($payload);
        $this->dispatcher->dispatch($event, Events::JWT_DECODED);


        if (!$event->isValid()) {
            return false;
        }

        return $event->getPayload();
    }

    /**
     * Add user identity to payload, username by default.
     * Override this if you need to identify it by another property.
     *
     * @param UserInterface $user
     * @param array         &$payload
     */
    protected function addUserIdentityToPayload(UserInterface $user, array &$payload)
    {
        $accessor                    = PropertyAccess::createPropertyAccessor();
        $payload[$this->userIdClaim ?: $this->userIdentityField] = $accessor->getValue($user, $this->userIdentityField);
    }

    /**
     * {@inheritdoc}
     */
    public function getUserIdentityField()
    {
        return $this->userIdentityField;
    }

    /**
     * {@inheritdoc}
     */
    public function setUserIdentityField($userIdentityField)
    {
        $this->userIdentityField = $userIdentityField;
    }

    /**
     * @return string
     */
    public function getUserIdClaim()
    {
        return $this->userIdClaim;
    }
}
